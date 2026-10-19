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
		foreach ($this->groupConfiguration->getEntries() as $entry) {
			try {
				if ($this->evaluator->matches($user, $entry['criteria'])) $groups[] = $entry['group'];
			} catch (\InvalidArgumentException) {
				// Invalid persisted criteria must never grant additional memberships.
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
