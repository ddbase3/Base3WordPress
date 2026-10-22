<?php declare(strict_types=1);

// Standalone CLI verification with WordPress fakes. Requires the installed BASE3 framework.
if (PHP_SAPI !== 'cli') return;

spl_autoload_register(static function(string $class): void {
	$modules = dirname(__DIR__, 3);
	foreach (['Base3\\' => $modules . '/Base3Framework/src/', 'Base3WordPress\\' => $modules . '/Base3WordPress/src/'] as $prefix => $root) {
		if (!str_starts_with($class, $prefix)) continue;
		$path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($path)) require_once $path;
		return;
	}
});

final class WP_Role {
	public function __construct(public string $name, public array $capabilities) {}
	public function add_cap(string $capability, bool $grant = true): void {
		$this->capabilities[$capability] = $grant;
		$GLOBALS['role_definitions'][$this->name]['capabilities'][$capability] = $grant;
	}
	public function remove_cap(string $capability): void {
		unset($this->capabilities[$capability], $GLOBALS['role_definitions'][$this->name]['capabilities'][$capability]);
	}
}

final class WP_User {
	public function __construct(
		public int $ID,
		public string $user_login,
		public string $user_email,
		public string $display_name,
		public array $roles,
		public array $allcaps,
		public string $user_pass = 'original'
	) {}
	public function exists(): bool { return $this->ID > 0; }
	public function add_role(string $role): void {
		if (!in_array($role, $this->roles, true)) $this->roles[] = $role;
	}
	public function remove_role(string $role): void {
		$this->roles = array_values(array_diff($this->roles, [$role]));
	}
}

$GLOBALS['role_definitions'] = [
	'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'read' => true, 'deprecated' => false]],
	'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true, 'read' => true]],
	'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true]]
];
$GLOBALS['role_objects'] = [];
foreach ($GLOBALS['role_definitions'] as $slug => $definition) {
	$GLOBALS['role_objects'][$slug] = new WP_Role($slug, $definition['capabilities']);
}
$GLOBALS['users'] = [
	1 => new WP_User(1, 'maintainer', 'owner@example.test', 'Owner', ['administrator'], [
		'administrator' => true, 'manage_options' => true, 'read' => true,
		'promote_users' => true, 'create_users' => true, 'list_users' => true
	]),
	2 => new WP_User(2, 'writer', 'writer@example.test', 'Writer', ['editor'], ['editor' => true, 'edit_posts' => true, 'read' => true]),
	3 => new WP_User(3, 'reader', 'reader@example.test', 'Reader', ['subscriber'], ['read' => true])
];
$GLOBALS['actor'] = 1;
$GLOBALS['editable'] = ['administrator', 'editor', 'subscriber'];
$GLOBALS['role_lookups'] = 0;
$GLOBALS['last_user_query'] = null;

function wp_get_current_user(): WP_User { return $GLOBALS['users'][$GLOBALS['actor']] ?? new WP_User(0, '', '', '', [], []); }
function get_current_user_id(): int { return $GLOBALS['actor']; }
function get_user_by(string $field, int|string $id): WP_User|false {
	return $field === 'id' ? ($GLOBALS['users'][(int)$id] ?? false) : false;
}
function get_user_locale(int $id): string { return $id === 2 ? 'de_DE' : 'en_US'; }
function wp_roles(): object {
	return (object)['roles' => $GLOBALS['role_definitions'], 'role_names' => array_map(
		static fn(array $definition): string => $definition['name'], $GLOBALS['role_definitions']
	)];
}
function get_role(string $name): ?WP_Role {
	$GLOBALS['role_lookups']++;
	return $GLOBALS['role_objects'][$name] ?? null;
}
function translate_user_role(string $name): string { return $name; }
function user_can(int|WP_User $user, string $capability, ?int $target = null): bool {
	$user = $user instanceof WP_User ? $user : ($GLOBALS['users'][$user] ?? null);
	if ($user === null) return false;
	if ($capability === 'promote_user') return $target !== null && !empty($user->allcaps['promote_users']);
	return !empty($user->allcaps[$capability]);
}
function current_user_can(string $capability, ?int $target = null): bool {
	return user_can(wp_get_current_user(), $capability, $target);
}
function apply_filters(string $hook, array $value): array {
	if ($hook !== 'editable_roles') return $value;
	return array_intersect_key($value, array_fill_keys($GLOBALS['editable'], true));
}
function get_users(array $args): array {
	$GLOBALS['last_user_query'] = $args;
	$rows = [];
	foreach ($GLOBALS['users'] as $user) {
		$rows[] = (object)[
			'ID' => $user->ID, 'user_login' => $user->user_login,
			'display_name' => $user->display_name, 'user_email' => $user->user_email
		];
	}
	usort($rows, static fn($a, $b): int => strcmp($a->display_name, $b->display_name) ?: ($a->ID <=> $b->ID));
	return $rows;
}
function wp_create_user(string $login, string $password, string $email): int {
	$id = count($GLOBALS['users']) + 1;
	$GLOBALS['users'][$id] = new WP_User($id, $login, $email, $login, ['subscriber'], ['read' => true], $password);
	return $id;
}
function wp_check_password(string $plain, string $stored, int $id): bool { return $plain === $stored; }
function wp_set_password(string $password, int $id): void { $GLOBALS['users'][$id]->user_pass = $password; }

function ensure(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException('FAIL ' . $message);
	echo 'PASS ' . $message . PHP_EOL;
}

$manager = (new ReflectionClass(\Base3WordPress\Adapter\WordPressUsermanager::class))->newInstanceWithoutConstructor();
$editor = \Base3\Usermanager\Role::named('editor');
$admin = \Base3\Usermanager\Role::named('administrator');

ensure($manager instanceof \Base3\Usermanager\Api\IUsermanager, 'implements complete IUsermanager contract');
ensure($manager->getUser()->role === 'admin' && $manager->getUser()->userid === 'maintainer', 'current detailed identity and compatibility role');
ensure($manager->getUserById('0002')->userid === 'writer', 'strict native numeric lookup supports leading zeros');
foreach (['2x', '2.0', '-2', '0', '9223372036854775808', '', 'reader'] as $invalid) {
	ensure($manager->getUserById($invalid) === null, 'invalid native ID rejected: ' . $invalid);
}
ensure($manager->hasRole($admin), 'native role slug matches');
ensure(!$manager->hasRole(\Base3\Usermanager\Role::named('admin')), 'compatibility admin is not a fabricated native role');
ensure($manager->hasRole(\Base3\Usermanager\Role::fromArray(['id' => 'administrator', 'name' => 'renamed'])), 'native role ID matches');

$roles = $manager->getRoles();
ensure(count($roles) === 1 && $roles[0]->id === 'administrator' && $roles[0]->name === 'administrator', 'native role identity');
ensure(count($roles[0]->permissions) === 2 && $roles[0]->permissions[0] instanceof \Base3\Usermanager\Permission, 'role permissions are DTOs, not strings');
ensure(count($manager->getAllRoles()) === 3, 'all native site roles exposed');
$effective = $manager->getPermissions();
$keys = array_map(static fn($permission): string => $permission->scope . '/' . $permission->permission, $effective);
ensure(in_array('system/admin', $keys, true) && in_array('wordpress/manage_options', $keys, true), 'current capability metadata includes compatibility admin');
ensure(!in_array('wordpress/administrator', $keys, true), 'role marker in allcaps is not a capability');
$catalog = $manager->getAllPermissions();
$catalogKeys = array_map(static fn($permission): string => $permission->scope . '/' . $permission->permission, $catalog);
ensure(in_array('wordpress/deprecated', $catalogKeys, true) && in_array('system/admin', $catalogKeys, true), 'catalog includes denied capability definitions');

$GLOBALS['role_lookups'] = 0;
$all = $manager->getAllUsers();
ensure(count($all) === 3 && $all[0]->userid === 'maintainer', 'native directory enumerates deterministically');
ensure($all[2]->lang === 'de' && $all[2]->roles === [] && $all[2]->role === '', 'bulk projection returns identity only with host locale');
ensure($GLOBALS['role_lookups'] === 0 && $GLOBALS['last_user_query']['fields'] === ['ID', 'user_login', 'display_name', 'user_email'], 'bulk directory does not expand native roles');

ensure($manager->assignRoleToUser(2, \Base3\Usermanager\Role::fromArray(['id'=>'subscriber', 'name'=>'wrong'])), 'native role assignment by ID');
ensure($manager->hasRole($admin), 'current user role unchanged');
ensure(in_array('subscriber', $GLOBALS['users'][2]->roles, true), 'target user gains native role');
ensure($manager->revokeRoleFromUser(2, \Base3\Usermanager\Role::named('subscriber')), 'native role revocation');
ensure(!in_array('subscriber', $GLOBALS['users'][2]->roles, true), 'target user loses native role');
ensure(!$manager->revokeRoleFromUser(1, $admin), 'self role revocation is rejected');
ensure(!$manager->revokeRoleFromUser(2, $admin), 'absent role cannot be revoked');
ensure(!$manager->assignRoleToGroup('editors', $editor) && !$manager->revokeRoleFromGroup('editors', $editor), 'no fictitious group role inheritance');

$GLOBALS['editable'] = ['subscriber'];
ensure(!$manager->assignRoleToUser(2, $admin), 'non-editable role cannot be assigned');
ensure(!$manager->addPermissionToRole($editor, \Base3\Usermanager\Permission::for('wordpress', 'upload_files')), 'non-editable role cannot gain capability');
$GLOBALS['editable'] = ['administrator', 'editor', 'subscriber'];
ensure($manager->addPermissionToRole($editor, \Base3\Usermanager\Permission::for('wordpress', 'upload_files')), 'native role capability grant');
ensure($manager->removePermissionFromRole($editor, \Base3\Usermanager\Permission::for('wordpress', 'upload_files')), 'native role capability removal');
ensure(!$manager->addPermissionToRole($editor, \Base3\Usermanager\Permission::for('system', 'admin')), 'non-native capability scope denied');
ensure(!$manager->removePermissionFromRole($editor, \Base3\Usermanager\Permission::for('wordpress', 'unknown')), 'unknown capability removal rejected');

ensure($manager->registUser('new-account', 'first-secret', ['email' => 'new@example.test']), 'native create_users registration');
ensure($manager->getUserById(4)->email === 'new@example.test', 'new account resolved by native ID');
ensure(!$manager->changePassword('wrong', 'replacement'), 'password change rejects wrong old password');
ensure($manager->changePassword('original', 'replacement'), 'native password change validates old credential');
ensure($GLOBALS['users'][1]->user_pass === 'replacement', 'native password change persisted');

$GLOBALS['actor'] = 2;
ensure(!$manager->assignRoleToUser(3, $admin) && !$manager->addPermissionToRole($editor, \Base3\Usermanager\Permission::for('wordpress', 'manage_options')), 'native role writes deny insufficient privilege');
ensure(!$manager->registUser('unauthorized', 'pw'), 'native user creation denies insufficient privilege');
ensure($manager->getAllUsers() === [], 'directory enumeration checks list_users');
ensure($manager->getAllPermissions() === [], 'capability catalog checks native administrative privilege');
$GLOBALS['actor'] = 0;
ensure($manager->getUser() === null && $manager->getRoles() === [] && $manager->getPermissions() === [], 'anonymous has no identity, roles or permissions');

echo 'WordPressUsermanagerSmokeTest OK' . PHP_EOL;
