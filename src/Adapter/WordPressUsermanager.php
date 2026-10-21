<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3WordPress\Service\WordPressGroupConfiguration;

/** WordPress users, native roles and capabilities exposed through BASE3 RBAC contracts. */
final class WordPressUsermanager implements IUsermanager {

	public function __construct(
		private readonly WordPressGroupConfiguration $groupConfiguration,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	public function getUser() {
		return $this->userObject(wp_get_current_user());
	}

	public function getUserById(int|string $id): ?User {
		return $this->userObject(get_user_by('id', (int) $id));
	}

	public function getGroups() {
		$user = $this->getUser();
		$groups = [];
		$entries = $this->groupConfiguration->getEntries();
		foreach ($entries as $entry) {
			try {
				if ($this->evaluator->matches($user, $entry['criteria'])) $groups[] = $entry['group'];
			} catch (\InvalidArgumentException) {
				// Invalid persisted criteria must never grant additional memberships.
			}
		}
		if ($user !== null && !array_filter($groups, static fn($group): bool => (string)$group->name !== 'authenticated')) {
			$fallback = $this->groupConfiguration->getDefaultGroup();
			foreach ($entries as $entry) {
				if ($fallback !== '' && (string)$entry['group']->name === $fallback) {
					$groups[] = $entry['group'];
					break;
				}
			}
		}
		return $groups;
	}

	public function getRoles() {
		$current = wp_get_current_user();
		return $current->exists() ? array_map([$this, 'roleObject'], $current->roles) : [];
	}

	public function getPermissions() {
		$current = wp_get_current_user();
		$permissions = [];
		if (!$current->exists()) return $permissions;
		foreach ($current->allcaps as $cap => $enabled) {
			if ($enabled) $permissions[] = Permission::for('wordpress', (string) $cap);
		}
		if (current_user_can('manage_options')) $permissions[] = Permission::for('system', 'admin');
		return $permissions;
	}

	public function hasRole(Role $role): bool {
		return in_array((string)($role->name ?? ''), wp_get_current_user()->roles, true);
	}

	public function registUser($userid, $password, $data = null) {
		if (!current_user_can('create_users') || !is_string($userid)) return false;
		$result = wp_create_user($userid, (string) $password, is_array($data) ? (string) ($data['email'] ?? '') : '');
		return !is_wp_error($result);
	}

	public function changePassword($oldpassword, $newpassword) {
		$user = wp_get_current_user();
		if (!$user->exists() || !wp_check_password((string) $oldpassword, $user->user_pass, $user->ID)) return false;
		wp_set_password((string) $newpassword, $user->ID);
		return true;
	}

	public function getAllUsers() {
		if (!current_user_can('list_users')) return [];
		return array_values(array_filter(array_map([$this, 'userObject'], get_users())));
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
			$users[] = User::fromArray([
				'id' => (int)$row->ID,
				'userid' => (string)$row->user_login,
				'name' => (string)$row->display_name,
				'email' => (string)$row->user_email,
				'lang' => strtolower(explode('_', $locale !== '' ? $locale : $defaultLocale)[0]),
				'role' => '',
				'roles' => []
			]);
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

	public function getAllGroups() {
		return $this->groupConfiguration->getGroups();
	}

	public function getAllRoles() {
		return array_map([$this, 'roleObject'], array_keys(wp_roles()->roles));
	}

	public function getAllPermissions() {
		if (!current_user_can('manage_options')) return [];
		$caps = [];
		foreach (wp_roles()->roles as $role) {
			foreach (($role['capabilities'] ?? []) as $cap => $allowed) {
				if ($allowed) $caps[$cap] = Permission::for('wordpress', (string) $cap);
			}
		}
		return array_values($caps);
	}

	public function assignRoleToUser($userid, Role $role): bool {
		if (!current_user_can('promote_users')) return false;
		$wpRole = get_role((string) $role->name);
		$user = get_user_by('id', (int) $userid);
		if ($wpRole === null || !$user) return false;
		$user->add_role((string) $role->name);
		return true;
	}

	public function revokeRoleFromUser($userid, Role $role): bool {
		if (!current_user_can('promote_users')) return false;
		$user = get_user_by('id', (int) $userid);
		if (!$user) return false;
		$user->remove_role((string) $role->name);
		return true;
	}

	public function assignRoleToGroup($groupid, Role $role): bool { return false; }
	public function revokeRoleFromGroup($groupid, Role $role): bool { return false; }

	public function addPermissionToRole(Role $role, Permission $permission): bool {
		if (!current_user_can('manage_options') || $permission->scope !== 'wordpress') return false;
		$wpRole = get_role((string) $role->name);
		if ($wpRole === null) return false;
		$wpRole->add_cap((string) $permission->permission, true);
		return true;
	}

	public function removePermissionFromRole(Role $role, Permission $permission): bool {
		if (!current_user_can('manage_options') || $permission->scope !== 'wordpress') return false;
		$wpRole = get_role((string) $role->name);
		if ($wpRole === null) return false;
		$wpRole->remove_cap((string) $permission->permission);
		return true;
	}

	private function userObject(mixed $source): ?User {
		if (!$source instanceof \WP_User || !$source->exists()) return null;
		return User::fromArray([
			'id' => $source->ID,
			'userid' => $source->user_login,
			'name' => $source->display_name,
			'email' => $source->user_email,
			'lang' => strtolower(explode('_', get_user_locale($source->ID))[0]),
			'role' => user_can($source, 'manage_options') ? 'admin' : 'member',
			'roles' => array_map([$this, 'roleObject'], $source->roles)
		]);
	}


	private function roleObject(string $name): Role {
		$role = get_role($name);
		return Role::fromArray(['id' => $name, 'name' => $name, 'label' => translate_user_role(wp_roles()->role_names[$name] ?? $name), 'permissions' => array_keys(array_filter($role?->capabilities ?? []))]);
	}
}
