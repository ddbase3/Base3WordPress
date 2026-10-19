<?php declare(strict_types=1);

ob_start();

// Isolated host contract smoke test; run with PHP CLI, no WordPress server or PHPUnit.
// This CLI-only environment may omit mbstring, which the shared framework requires.
if (!function_exists('mb_strlen')) { function mb_strlen(string $value): int { return strlen($value); } }
spl_autoload_register(static function(string $class): void {
	$root = dirname(__DIR__, 3);
	$prefixes = [
		'Base3\\' => $root . '/Base3Framework/src/',
		'Base3WordPress\\' => $root . '/Base3WordPress/src/',
		'Base3WordPressLab\\' => $root . '/Base3WordPressLab/src/',
		'PulseCore\\' => $root . '/PulseCore/src/'
	];
	foreach ($prefixes as $prefix => $base) {
		if (!str_starts_with($class, $prefix)) continue;
		$file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require_once $file;
		return;
	}
});

class WP_User {
	public function __construct(
		public int $ID,
		public string $user_login,
		public string $user_email,
		public array $roles,
		public array $allcaps = [],
		public string $display_name = 'Sample'
	) {}
	public function exists(): bool { return $this->ID > 0; }
}

$GLOBALS['users'] = [
	1 => new WP_User(1, 'maintainer', 'owner@example.org', ['administrator'], ['manage_options'=>true, 'read'=>true]),
	2 => new WP_User(2, 'content-editor', 'writer@example.org', ['editor'], ['read'=>true, 'edit_posts'=>true]),
	3 => new WP_User(3, 'visitor', 'person@qualitus.de', ['subscriber'], ['read'=>true])
];
$GLOBALS['who'] = 1;

define('BASE3_WORDPRESS_DEVELOPER_USERNAMES', ['maintainer']);
function wp_get_current_user(): WP_User { return $GLOBALS['users'][$GLOBALS['who']] ?? new WP_User(0, '', '', []); }
function get_current_user_id(): int { return $GLOBALS['who']; }
function get_user_by(string $field, int|string $id): WP_User|false {
	if ($field === 'id') return $GLOBALS['users'][(int)$id] ?? false;
	foreach ($GLOBALS['users'] as $user) if ($field === 'login' && $user->user_login === $id) return $user;
	return false;
}
function wp_roles(): object {
	return (object)[
		'roles' => [
			'administrator' => ['name'=>'Administrator','capabilities'=>['manage_options'=>true,'read'=>true]],
			'editor' => ['name'=>'Editor','capabilities'=>['edit_posts'=>true,'read'=>true]],
			'subscriber' => ['name'=>'Subscriber','capabilities'=>['read'=>true]]
		],
		'role_names' => ['administrator'=>'Administrator','editor'=>'Editor','subscriber'=>'Subscriber']
	];
}
function get_role(string $slug): ?object {
	$role = wp_roles()->roles[$slug] ?? null;
	return $role === null ? null : (object)['capabilities'=>$role['capabilities']];
}
function translate_user_role(string $label): string { return $label; }
function get_user_locale(int $userId): string { return 'en_US'; }
function user_can(int|WP_User $user, string $capability, int|string|null $id = null): bool {
	$identity = $user instanceof WP_User ? $user : get_user_by('id', $user);
	if (!$identity) return false;
	if ($id !== null && !in_array($capability, ['edit_post','delete_post','read_post','edit_user','delete_user','remove_user','promote_user','edit_term','delete_term','assign_term','edit_comment'], true)) return false;
	if ($id !== null) return $identity->ID === 1 && (int)$id === 55 && $capability === 'edit_post';
	return (bool)($identity->allcaps[$capability] ?? false);
}
function current_user_can(string $capability, int|string|null $id = null): bool { return user_can(wp_get_current_user(), $capability, $id); }
function wp_create_nonce(string $action): string { return 'test-nonce'; }
function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'test-nonce' ? 1 : false; }

final class InMemorySettingsStore implements \Base3\Settings\Api\ISettingsStore {
	public array $rows = [];
	public function get(string $group, string $name, array $default = []): array { return $this->rows["$group/$name"] ?? $default; }
	public function set(string $group, string $name, array $settings): void { $this->rows["$group/$name"] = $settings; }
	public function has(string $group, string $name): bool { return isset($this->rows["$group/$name"]); }
	public function remove(string $group, string $name): void { unset($this->rows["$group/$name"]); }
	public function getGroup(string $group): array { return []; }
	public function save(): void {}
	public function reload(): void {}
}

final class AudienceTestClassMap implements \Base3\Api\IClassMap {
	public array $instances = [];
	private mixed $nothing = null;
	public function __construct() {
		$this->instances = [
			new \Base3WordPress\Group\Base3WordPressAnonymousGroup(),
			new \Base3WordPress\Group\Base3WordPressAuthenticatedGroup(),
			new \Base3WordPress\Group\Base3WordPressAdministrationGroup(),
			new \Base3WordPressLab\Group\Base3WordPressDevelopmentGroup(),
			new \Base3WordPress\Criterion\WordPressRoleCriterion(),
			new \Base3WordPress\Criterion\WordPressCapabilityCriterion(),
			new \Base3\Usermanager\Criterion\Base3AnonymousCriterion(),
			new \Base3\Usermanager\Criterion\Base3AuthenticatedCriterion(),
			new \Base3\Usermanager\Criterion\Base3UserIdCriterion(),
			new \Base3\Usermanager\Criterion\Base3UserIdentityCriterion(),
			new \Base3\Usermanager\Criterion\Base3EmailDomainCriterion(),
			new \PulseCore\Feature\PulseCorePlayFeature()
		];
	}
	public function instantiate(string $class) { return new $class(); }
	public function instantiateWith(string $class, array $arguments = []) { return new $class(); }
	public function generate($regenerate = false): void {}
	public function getApps() { return []; }
	public function &getInstances(array $criteria = []) { $items = []; return $items; }
	public function &getInstancesByInterface($interface) {
		$items = array_values(array_filter($this->instances, static fn($instance) => $instance instanceof $interface));
		return $items;
	}
	public function &getInstancesByAppInterface($app, $interface, $retry = false) {
		return $this->getInstancesByInterface($interface);
	}
	public function &getInstanceByAppName($app, $name, $retry = false) { return $this->nothing; }
	public function getClassByInterfaceName(string $interface, string $name): ?string {
		foreach ($this->instances as $instance) if ($instance instanceof $interface && $instance::getName() === $name) return $instance::class;
		return null;
	}
	public function &getInstanceByInterfaceName($interface, $name, $retry = false) {
		foreach ($this->instances as &$instance) if ($instance instanceof $interface && $instance::getName() === $name) return $instance;
		return $this->nothing;
	}
	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) {
		return $this->getInstanceByInterfaceName($interface, $name);
	}
	public function getPlugins() { return []; }
}

function verify(bool $condition, string $description): void {
	if (!$condition) throw new RuntimeException('FAILED: ' . $description);
	echo 'PASS ' . $description . PHP_EOL;
}

$map = new AudienceTestClassMap();
$store = new InMemorySettingsStore();
$evaluator = new \Base3\Usermanager\UserCriterionEvaluator($map);
$features = new \Base3WordPress\Service\WordPressFeatureConfiguration($store, $map, $evaluator);
$groups = new \Base3WordPress\Service\WordPressGroupConfiguration($store, $map, $evaluator, $features);
$manager = new \Base3WordPress\Adapter\WordPressUsermanager($groups, $evaluator);
$service = new \Base3WordPress\Authorization\WordPressAuthorizationService($manager, $features, $evaluator);
$editor = new \Base3WordPress\Service\WordPressAudienceEditor($map, $evaluator);
$permission = static fn(string $scope, string $operation, ?\Base3\Authorization\Dto\ResourceReference $resource = null) =>
	new \Base3\Authorization\Dto\AuthorizationRequest('wordpress', \Base3\Usermanager\Permission::for($scope, $operation), $resource);
$names = static fn() => array_map(static fn($g) => $g->name, $manager->getGroups());
verify(in_array('development', $names(), true), 'Bootstrap developer matches exact login');
verify(in_array('administration', $names(), true), 'Native WordPress manage_options yields administration group');
verify($service->decide($permission('system','admin'))->isAllowed(), 'WordPress manage_options authorizes BASE3 system/admin');
verify($service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'PulseCore authenticated default works');
verify($service->decide($permission('wordpress','edit_post', new \Base3\Authorization\Dto\ResourceReference('wordpress','post',55)))->isAllowed(), 'WordPress edit_post meta-capability uses native resource ID');
verify(!$service->decide($permission('wordpress','edit_post', new \Base3\Authorization\Dto\ResourceReference('wordpress','post',99)))->isAllowed(), 'Wrong resource ID denies');
verify(!$service->decide(new \Base3\Authorization\Dto\AuthorizationRequest('wordpress', \Base3\Usermanager\Permission::for('system','admin'), null, new \Base3\Authorization\Dto\SubjectReference('wordpress','user',2)))->isAllowed(), 'Delegated principal denied');
verify(!$service->decide(new \Base3\Authorization\Dto\AuthorizationRequest('other', \Base3\Usermanager\Permission::for('system','admin')))->isAllowed(), 'Unknown authority fails closed');
$GLOBALS['who'] = 2;
verify(!in_array('development', $names(), true), 'Ordinary native editor is not an implicit BASE3 developer');
verify(!$service->decide($permission('system','admin'))->isAllowed(), 'WordPress editor cannot administer system');
verify($service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'WordPress editor may play PulseCore by authenticated default');
$rules = ['pulsecoreplayfeature' => $editor->parse([['kind'=>'role','value'=>'editor']], array_map(static fn($g) => $g->name, $groups->getGroups()))];
$features->replaceAll($rules, array_map(static fn($g) => $g->name, $groups->getGroups()));
verify($service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Native role grants a feature without admin capability');
$GLOBALS['who'] = 1;
verify(!$service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Feature override excludes other roles');
$features->replaceAll(['pulsecoreplayfeature'=>[]], array_map(static fn($g) => $g->name, $groups->getGroups()));
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Explicit empty feature override denies');
$criterionRule = $editor->parse([['kind'=>'criterion','value'=>'base3emaildomaincriterion','config'=>'{"domain":"qualitus.de"}']], array_map(static fn($g) => $g->name, $groups->getGroups()));
$features->replaceAll(['pulsecoreplayfeature'=>$criterionRule], array_map(static fn($g) => $g->name, $groups->getGroups()));
$GLOBALS['who'] = 3;
verify($service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Discoverable email-domain criterion grants feature without native role');
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Unmatched email domain does not grant feature');
$nested = $editor->parse([['kind'=>'expression','config'=>'{"all":[{"group":"authenticated"},{"criterion":"base3emaildomaincriterion","config":{"domain":"qualitus.de"}}]}']], array_map(static fn($g) => $g->name, $groups->getGroups()));
verify($editor->describe($nested)[0]['kind'] === 'expression', 'Complex AND expression round-trips without flattening');
$features->replaceAll(['pulsecoreplayfeature'=>$nested], array_map(static fn($g) => $g->name, $groups->getGroups()));
$GLOBALS['who'] = 3;
verify($service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Nested audience AND uses one evaluator');
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Nested audience requires both conditions');

$groups->create('reviewers','Review team');
$groups->replaceAll(['reviewers'=>$editor->parse([['kind'=>'role','value'=>'editor']],null)]);
verify(in_array('reviewers', $names(), true), 'Custom group membership uses the shared native role criterion');
$features->replaceAll(['pulsecoreplayfeature'=>['group'=>'reviewers']], array_map(static fn($g) => $g->name, $groups->getGroups()));
$rejected = false;
try { $groups->remove('reviewers'); } catch (InvalidArgumentException) { $rejected = true; }
verify($rejected, 'Custom group referenced by feature cannot be removed');
$GLOBALS['who'] = 1;
$native = new \Base3WordPress\Authorization\WordPressAuthorizationAdministrationService();
$site = new \Base3\Authorization\Dto\ResourceReference('wordpress','site','global');
verify(!$native->describe('wordpress', $site)->canWrite, 'WordPress native capability catalogue explicitly reports read-only');
verify(count($native->getSubjects($site)) === 3 && count($native->getGrants($site)) > 0, 'Native roles and grants come from WordPress');
$GLOBALS['who'] = 0;
verify(in_array('anonymous', $names(), true), 'Anonymous visitor retains BASE3 anonymous group');
verify(!$service->decide($permission('feature','pulsecoreplayfeature'))->isAllowed(), 'Unauthenticated visitor denied against non-public feature audience');
echo 'WordPressAuthorizationSmokeTest OK' . PHP_EOL;

final class AudienceTestTranslation implements \Base3\Translation\Api\ITranslation {
	public function translate(string $set, string $section, string $key, string $fallback = '', array $replacements = []): string { return $fallback; }
}
final class AudienceTestLinks implements \Base3\LinkTarget\Api\ILinkTargetService {
	public function getLink(array $target, array $params = []): string { return 'admin-ajax.php?' . http_build_query(array_merge(['name'=>$target['name'],'out'=>$target['out']], $params)); }
}
final class AudienceTestView implements \Base3\Api\IMvcView {
	private array $_ = [];
	private string $path = '';
	private string $file = '';
	public function setPath(string $path = '.') { $this->path = $path; }
	public function assign(string $key, $value) { $this->_[$key] = $value; }
	public function setTemplate(string $template = 'default') { $this->file = $template; }
	public function loadTemplate(): string {
		ob_start();
		include $this->path . '/tpl/' . $this->file;
		return (string)ob_get_clean();
	}
	public function loadBricks(string $set, string $language = '') {}
	public function getBricks(string $set): ?array { return null; }
}
$GLOBALS['users'][4] = new WP_User(4, 'other-site-admin', 'admin@example.org', ['administrator'], ['manage_options'=>true,'read'=>true]);
$settings = new \Base3WordPressLab\Service\Base3WordPressLabSettings(new AudienceTestTranslation(), new AudienceTestLinks(), $manager);
$GLOBALS['who'] = 4;
$reduced = $settings->getAdministrationConfig();
verify(in_array('base3', array_column($reduced, 'name'), true), 'Non-development WordPress administrator retains reduced BASE3 tools');
verify(!in_array('provider', array_column($reduced, 'name'), true), 'Provider tab removed from ordinary admin navigation');
verify($settings->getAdministrationAccessLevel('base3wordpressaudienceadmindisplay') === 'development', 'Direct audience AJAX requests classified as developer-only');
verify($settings->getAdministrationAccessLevel('tabcontroldisplay') === 'development', 'Generic nested tab composition is developer-only, not public AJAX');
verify($settings->getAdministrationAccessLevel('pulsecoredisplay') === null, 'Games remain independently feature-authorized and embeddable');
$GLOBALS['who'] = 1;
$full = $settings->getAdministrationConfig();
verify(in_array('provider', array_column($full, 'name'), true), 'Code-owned developer receives full navigation');
$base3 = $full[array_search('base3', array_column($full, 'name'), true)];
verify(in_array('base3wordpressgroups', array_column($base3['displays'], 'name'), true)
	&& in_array('base3wordpressfeatures', array_column($base3['displays'], 'name'), true), 'Groups and Features appear in full BASE3 navigation');
$_GET = ['base3_mode'=>'features'];
$_POST = [];
$display = new \Base3WordPress\Display\Base3WordPressAudienceAdminDisplay(
	$service, $manager, \Base3\Core\Request::fromGlobals(), new AudienceTestView(), new AudienceTestLinks(), $groups, $features, $editor
);
$display->setData(['mode'=>'features']);
$html = $display->getOutput('html', true);
verify(str_contains($html, 'BASE3 features') && str_contains($html, 'WordPress role') && str_contains($html, 'pulsecoreplayfeature'), 'Shared editor renders discovered feature and native role selectors');
$_GET = ['base3_mode'=>'groups'];
$_POST = ['base3_audience_action'=>'create', 'base3_audience_nonce'=>'test-nonce', 'base3_group_name'=>'webteam', 'base3_group_description'=>'Website team'];
$display = new \Base3WordPress\Display\Base3WordPressAudienceAdminDisplay(
	$service, $manager, \Base3\Core\Request::fromGlobals(), new AudienceTestView(), new AudienceTestLinks(), $groups, $features, $editor
);
verify(str_contains($display->getOutput('html', true), 'BASE3 group created.'), 'WordPress nonce-protected group create uses existing settings store');
$GLOBALS['who'] = 4;
$_POST = [];
$display = new \Base3WordPress\Display\Base3WordPressAudienceAdminDisplay(
	$service, $manager, \Base3\Core\Request::fromGlobals(), new AudienceTestView(), new AudienceTestLinks(), $groups, $features, $editor
);
http_response_code(200);
verify(str_contains($display->getOutput('html', true), 'access denied') && http_response_code() === 403,
	'Native site administrator without BASE3 development cannot open direct group configuration');
echo 'WordPress project navigation and display tests OK' . PHP_EOL;

ob_end_flush();
