<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3WordPress\Service\WordPressGroupConfiguration;

/** Exposes native WordPress identity, roles and capability metadata to BASE3. */
final class WordPressUsermanager implements IUsermanager {

	public function __construct(
		private readonly WordPressGroupConfiguration $groupConfiguration,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	/** Returns the authenticated WordPress identity with native roles, or null. */
	public function getUser(): ?User {
		return $this->userObject(wp_get_current_user());
	}

	/** Looks up a native positive user ID, never an arbitrary login or cast prefix. */
	public function getUserById(int|string $id): ?User {
		$userId = $this->normalizeUserId($id);
		return $userId === null ? null : $this->userObject(get_user_by('id', $userId));
	}

	/** Evaluates effective BASE3 groups without altering native WordPress roles. */
	public function getGroups(): array {
		$user = $this->getUser();
		$entries = $this->groupConfiguration->getEntries();
		$groups = [];
		$explicitMatch = false;

		foreach ($entries as $entry) {
			try {
				$matched = $this->evaluator->matches($user, $entry['criteria']);
			} catch (\InvalidArgumentException) {
				// Invalid configured criteria cannot grant membership.
				$matched = false;
			}
			if (!$matched) continue;
			$groups[] = $entry['group'];
			if ($entry['editable']) $explicitMatch = true;
		}

		if ($user !== null && !$explicitMatch) {
			$fallback = $this->groupConfiguration->getDefaultGroup();
			foreach ($entries as $entry) {
				if (!$entry['editable'] || (string)$entry['group']->name !== $fallback) continue;
				$groups[] = $entry['group'];
				break;
			}
		}

		return $groups;
	}

	/** Returns the current user's assigned native site roles. */
	public function getRoles(): array {
		$current = wp_get_current_user();
		return $current->exists() ? array_map([$this, 'roleObject'], $current->roles) : [];
	}

	/** Lists enabled capability metadata, excluding role slugs stored in allcaps. */
	public function getPermissions(): array {
		$current = wp_get_current_user();
		if (!$current->exists()) return [];

		$roles = wp_roles()->roles;
		$permissions = [];
		foreach ($current->allcaps as $capability => $enabled) {
			if (!$enabled || isset($roles[$capability])) continue;
			$permissions[(string)$capability] = Permission::for('wordpress', (string)$capability);
		}
		ksort($permissions);
		if (user_can($current, 'manage_options')) {
			$permissions['system/admin'] = Permission::for('system', 'admin');
		}
		return array_values($permissions);
	}

	/** Tests membership by WordPress role slug, not by its translated display label. */
	public function hasRole(Role $role): bool {
		$current = wp_get_current_user();
		if (!$current->exists()) return false;

		$id = (string)($role->id ?? '');
		$name = (string)($role->name ?? '');
		foreach ($current->roles as $assigned) {
			if ($id !== '' && $assigned === $id) return true;
			if ($name !== '' && $assigned === $name) return true;
		}
		return false;
	}

	/** Uses the host's account creation API, subject to its native create_users cap. */
	public function registUser($userid, $password, $data = null): bool {
		if (!current_user_can('create_users') || !is_string($userid) || $userid === '') return false;
		$email = is_array($data) ? (string)($data['email'] ?? '') : '';
		$result = wp_create_user($userid, (string)$password, $email);
		return is_int($result) && $result > 0;
	}

	/** Verifies the old password before asking WordPress to replace it. */
	public function changePassword($oldpassword, $newpassword): bool {
		$user = wp_get_current_user();
		if (!$user->exists() || !wp_check_password((string)$oldpassword, $user->user_pass, $user->ID)) return false;
		wp_set_password((string)$newpassword, $user->ID);
		return true;
	}

	/**
	 * Enumerates visible site identities without expanding each user's roles.
	 * Native WP_User_Query applies the current blog membership filter on multisite.
	 *
	 * @return User[] Native users with identity fields only, sorted by display name
	 */
	public function getAllUsers(): array {
		if (!current_user_can('list_users')) return [];

		$rows = get_users([
			'fields' => ['ID', 'user_login', 'display_name', 'user_email'],
			'orderby' => ['display_name' => 'ASC', 'ID' => 'ASC'],
			'count_total' => false
		]);
		$users = [];
		foreach ($rows as $row) {
			$users[] = $this->directoryUser(
				(int)$row->ID,
				(string)$row->user_login,
				(string)$row->display_name,
				(string)$row->user_email,
				get_user_locale((int)$row->ID)
			);
		}
		return $users;
	}

	/**
	 * Searches the current WordPress user directory using one bounded native SQL query.
	 *
	 * WordPress stores first and last names as user metadata, so searching only
	 * WP_User_Query's user-table columns would miss valid name matches. Results
	 * contain identity fields only; native roles and capabilities are not expanded.
	 * The same list_users visibility boundary as getAllUsers() is respected.
	 *
	 * @param string $query Literal text matched against login, display/full name,
	 *                      first name, last name or email; digits also match exact IDs
	 * @param int $limit Maximum number of results, from 1 through 100
	 * @param int $offset Number of matching users to skip, zero or greater
	 * @return User[] Native WordPress identities ordered by display name and ID
	 * @throws \InvalidArgumentException For pagination outside the supported range
	 * @throws \RuntimeException If the WordPress database query fails
	 */
	public function searchUsers(string $query, int $limit = 20, int $offset = 0): array {
		if ($limit < 1 || $limit > 100 || $offset < 0) {
			throw new \InvalidArgumentException('User search requires a limit of 1..100 and a non-negative offset.');
		}

		$query = trim($query);
		if ($query === '' || !current_user_can('list_users')) return [];

		global $wpdb;
		$membershipKey = is_multisite()
			? $wpdb->get_blog_prefix(get_current_blog_id()) . 'capabilities'
			: null;
		$sql = $this->prepareSearchUsersQuery($wpdb, $query, $limit, $offset, $membershipKey);
		$rows = $wpdb->get_results($sql);
		if ($wpdb->last_error !== '' || !is_array($rows)) {
			throw new \RuntimeException('WordPress user directory search failed.');
		}

		$defaultLocale = get_locale();
		$users = [];
		foreach ($rows as $row) {
			$locale = trim((string)($row->user_locale ?? ''));
			$users[] = $this->directoryUser(
				(int)$row->ID,
				(string)$row->user_login,
				(string)$row->display_name,
				(string)$row->user_email,
				$locale !== '' ? $locale : $defaultLocale
			);
		}

		return $users;
	}

	/**
	 * Prepares the bounded native query using WordPress's own table names and SQL API.
	 *
	 * The joined metadata may contain duplicate keys; DISTINCT prevents duplicates
	 * from shifting page boundaries. Multisite queries require membership in the
	 * current site's native capabilities record, as does WP_User_Query.
	 *
	 * @param object $database The current WordPress wpdb instance
	 * @param string $query Non-empty, trimmed literal search text
	 * @param int $limit Validated page size
	 * @param int $offset Validated page offset
	 * @param string|null $membershipKey Native current-site membership metadata key
	 * @return string Fully prepared SQL with escaped search values
	 */
	private function prepareSearchUsersQuery(
		object $database,
		string $query,
		int $limit,
		int $offset,
		?string $membershipKey
	): string {
		$pattern = '%' . $database->esc_like($query) . '%';
		$conditions = [
			'LOWER(u.user_login) LIKE LOWER(%s)',
			'LOWER(u.display_name) LIKE LOWER(%s)',
			'LOWER(u.user_email) LIKE LOWER(%s)',
			'LOWER(first_name.meta_value) LIKE LOWER(%s)',
			'LOWER(last_name.meta_value) LIKE LOWER(%s)',
			"LOWER(CONCAT_WS(' ', first_name.meta_value, last_name.meta_value)) LIKE LOWER(%s)"
		];
		$values = array_fill(0, count($conditions), $pattern);

		// Avoid numeric overflow and preserve substring searches for long numbers.
		$numeric = ctype_digit($query)
			? filter_var(ltrim($query, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
			: false;
		if ($numeric !== false) {
			$conditions[] = 'u.ID = %d';
			$values[] = $numeric;
		}

		$membershipSql = '';
		if ($membershipKey !== null) {
			$membershipSql = " AND EXISTS (SELECT 1 FROM {$database->usermeta} AS member"
				. ' WHERE member.user_id = u.ID AND member.meta_key = %s)';
			$values[] = $membershipKey;
		}
		$values[] = $limit;
		$values[] = $offset;

		$sql = "SELECT DISTINCT u.ID, u.user_login, u.display_name, u.user_email,"
			. " (SELECT lang.meta_value FROM {$database->usermeta} AS lang"
			. " WHERE lang.user_id = u.ID AND lang.meta_key = 'locale'"
			. ' ORDER BY lang.umeta_id DESC LIMIT 1) AS user_locale'
			. " FROM {$database->users} AS u"
			. " LEFT JOIN {$database->usermeta} AS first_name"
			. " ON first_name.user_id = u.ID AND first_name.meta_key = 'first_name'"
			. " LEFT JOIN {$database->usermeta} AS last_name"
			. " ON last_name.user_id = u.ID AND last_name.meta_key = 'last_name'"
			. ' WHERE (' . implode(' OR ', $conditions) . ')'
			. $membershipSql
			. ' ORDER BY u.display_name, u.ID LIMIT %d OFFSET %d';

		return $database->prepare($sql, ...$values);
	}

	/** Lists discovered code-owned and managed BASE3 groups, not WordPress roles. */
	public function getAllGroups(): array {
		return $this->groupConfiguration->getGroups();
	}

	/** Lists all registered site role definitions, regardless of current membership. */
	public function getAllRoles(): array {
		return array_map([$this, 'roleObject'], array_keys(wp_roles()->roles));
	}

	/** Returns the native capability catalog, including explicitly denied capabilities. */
	public function getAllPermissions(): array {
		if (!current_user_can('manage_options')) return [];

		$capabilities = [];
		foreach (wp_roles()->roles as $role) {
			foreach (($role['capabilities'] ?? []) as $capability => $_) {
				$capabilities[(string)$capability] = Permission::for('wordpress', (string)$capability);
			}
		}
		ksort($capabilities);
		$permissions = array_values($capabilities);
		$permissions[] = Permission::for('system', 'admin');
		return $permissions;
	}

	/** Adds an editable native site role to a user after WordPress's promote_user check. */
	public function assignRoleToUser($userid, Role $role): bool {
		$userId = $this->normalizeUserId($userid);
		$name = (string)($role->id ?? $role->name ?? '');
		if ($userId === null || $name === '' || !current_user_can('promote_user', $userId) || !$this->isEditableRole($name)) return false;

		$user = get_user_by('id', $userId);
		if (!$user instanceof \WP_User || !$user->exists()) return false;
		if (in_array($name, $user->roles, true)) return true;
		$user->add_role($name);
		return in_array($name, $user->roles, true);
	}

	/** Removes an existing role without allowing self-removal or bypassing native role filters. */
	public function revokeRoleFromUser($userid, Role $role): bool {
		$userId = $this->normalizeUserId($userid);
		$name = (string)($role->id ?? $role->name ?? '');
		if ($userId === null || $name === '' || $userId === get_current_user_id()
			|| !current_user_can('promote_user', $userId) || !$this->isEditableRole($name)) return false;

		$user = get_user_by('id', $userId);
		if (!$user instanceof \WP_User || !$user->exists() || !in_array($name, $user->roles, true)) return false;
		$user->remove_role($name);
		return !in_array($name, $user->roles, true);
	}

	/** WordPress core has no native group-to-role inheritance relationship. */
	public function assignRoleToGroup($groupid, Role $role): bool { return false; }

	/** WordPress core has no native group-to-role inheritance relationship. */
	public function revokeRoleFromGroup($groupid, Role $role): bool { return false; }

	/** Adds a site-wide WordPress role capability, not a resource-specific grant. */
	public function addPermissionToRole(Role $role, Permission $permission): bool {
		$name = (string)($role->id ?? $role->name ?? '');
		$capability = (string)($permission->permission ?? '');
		if (!current_user_can('manage_options') || $permission->scope !== 'wordpress'
			|| $capability === '' || !$this->isEditableRole($name)) return false;
		$wpRole = get_role($name);
		if ($wpRole === null) return false;
		$wpRole->add_cap($capability, true);
		return ($wpRole->capabilities[$capability] ?? false) === true;
	}

	/** Removes a site-wide WordPress role capability using the native role API. */
	public function removePermissionFromRole(Role $role, Permission $permission): bool {
		$name = (string)($role->id ?? $role->name ?? '');
		$capability = (string)($permission->permission ?? '');
		if (!current_user_can('manage_options') || $permission->scope !== 'wordpress'
			|| $capability === '' || !$this->isEditableRole($name)) return false;
		$wpRole = get_role($name);
		if ($wpRole === null || !array_key_exists($capability, $wpRole->capabilities)) return false;
		$wpRole->remove_cap($capability);
		return !array_key_exists($capability, $wpRole->capabilities);
	}

	/** Projects a detailed trusted host user, including native site roles. */
	private function userObject(mixed $source): ?User {
		if (!$source instanceof \WP_User || !$source->exists()) return null;
		$user = $this->directoryUser(
			(int)$source->ID,
			(string)$source->user_login,
			(string)$source->display_name,
			(string)$source->user_email,
			get_user_locale((int)$source->ID)
		);
		$user->role = user_can($source, 'manage_options') ? 'admin' : 'member';
		$user->roles = array_map([$this, 'roleObject'], $source->roles);
		return $user;
	}

	/** Converts only native directory fields without expanding user permissions. */
	private function directoryUser(int $id, string $login, string $name, string $email, string $locale): User {
		return User::fromArray([
			'id' => $id,
			'userid' => $login,
			'name' => $name !== '' ? $name : $login,
			'email' => $email,
			'lang' => strtolower(explode('_', $locale)[0]),
			'role' => '',
			'roles' => []
		]);
	}

	/** Maps a native role slug and its granted capabilities to BASE3 metadata. */
	private function roleObject(string $name): Role {
		$role = get_role($name);
		$permissions = [];
		foreach ($role?->capabilities ?? [] as $capability => $enabled) {
			if ($enabled) $permissions[] = Permission::for('wordpress', (string)$capability);
		}
		return Role::fromArray([
			'id' => $name,
			'name' => $name,
			'label' => translate_user_role(wp_roles()->role_names[$name] ?? $name),
			'permissions' => $permissions
		]);
	}

	/** WordPress admin filters define which native role slugs the actor may edit. */
	private function isEditableRole(string $name): bool {
		if ($name === '' || get_role($name) === null) return false;
		$editable = apply_filters('editable_roles', wp_roles()->roles);
		return is_array($editable) && isset($editable[$name]);
	}

	/** Rejects malformed, overflowing or cross-host user identifiers before native lookup. */
	private function normalizeUserId(int|string $id): ?int {
		if (is_int($id)) return $id > 0 ? $id : null;
		if ($id === '' || !ctype_digit($id)) return null;
		$normalized = ltrim($id, '0');
		if ($normalized === '') return null;
		$value = filter_var($normalized, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		return $value === false ? null : $value;
	}
}
