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
		'ClientStack\\' => $root . '/ClientStack/src/',
		'UiFoundation\\' => $root . '/UiFoundation/src/',
		'PulseCore\\' => $root . '/PulseCore/src/'
	];
	foreach ($prefixes as $prefix => $base) {
		if (!str_starts_with($class, $prefix)) continue;
		$file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require_once $file;
		return;
	}
});

require_once __DIR__ . '/../Support/WordPressDemoAudienceFeature.php';
require_once __DIR__ . '/../Support/AudienceTestAssetResolver.php';

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
			new \Base3\Usermanager\Definition\Base3AnonymousGroup(),
			new \Base3\Usermanager\Definition\Base3AuthenticatedGroup(),
			new \Base3WordPress\Group\Base3WordPressAdministrationGroup(),
			new \Base3WordPressLab\Group\Base3WordPressDevelopmentGroup(),
			new \Base3WordPress\Criterion\WordPressRoleCriterion(),
			new \Base3WordPress\Criterion\WordPressCapabilityCriterion(),
			new \Base3\Usermanager\Criterion\Base3AnonymousCriterion(),
			new \Base3\Usermanager\Criterion\Base3AuthenticatedCriterion(),
			new \Base3\Usermanager\Criterion\Base3UserIdCriterion(),
			new \Base3\Usermanager\Criterion\Base3UserIdentityCriterion(),
			new \Base3\Usermanager\Criterion\Base3EmailDomainCriterion(),
			new \Base3WordPress\Test\Support\WordPressDemoAudienceFeature(),
			new \ClientStack\GlobalChatbotLauncher\GlobalChatbotLauncherFeature(new AudienceTestTranslation())
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

final class AudienceTestTranslation implements \Base3\Translation\Api\ITranslation {
	public function translate(string $set, string $section, string $key, string $fallback = '', array $replacements = []): string { return $fallback; }
}
final class AudienceTestLinks implements \Base3\LinkTarget\Api\ILinkTargetService {
	public function getLink(array $target, array $params = []): string { return 'admin-ajax.php?' . http_build_query(array_merge(['name'=>$target['name'],'out'=>$target['out']], $params)); }
}
final class AudienceTestSession implements \Base3\Session\Api\ISession {
	private array $data = [];
	public function started(): bool { return true; }
	public function getId(): string { return 'test-session'; }
	public function start(): bool { return true; }
	public function destroy(): bool { $this->data = []; return true; }
	public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
	public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
	public function has(string $key): bool { return array_key_exists($key, $this->data); }
	public function remove(string $key): void { unset($this->data[$key]); }
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

$map = new AudienceTestClassMap();
$store = new InMemorySettingsStore();
$evaluator = new \Base3\Usermanager\UserCriterionEvaluator($map);
$features = new \Base3WordPress\Service\WordPressFeatureConfiguration($store, $map, $evaluator);
$groups = new \Base3WordPress\Service\WordPressGroupConfiguration($store, $map, $evaluator, $features);
$manager = new \Base3WordPress\Adapter\WordPressUsermanager($groups, $evaluator);
$service = new \Base3WordPress\Authorization\WordPressAuthorizationService($manager, $features, $evaluator);
$map->instances[] = new \ClientStack\SettingsTab\AuthorizationgroupsSettingsTab(new AudienceTestTranslation(), $service);
$map->instances[] = new \ClientStack\SettingsTab\AuthorizationfeaturesSettingsTab(new AudienceTestTranslation(), $service);
$editor = new \Base3WordPress\Service\WordPressAudienceEditor($map, $evaluator);
$permission = static fn(string $scope, string $operation, ?\Base3\Authorization\Dto\ResourceReference $resource = null) =>
	new \Base3\Authorization\Dto\AuthorizationRequest('wordpress', \Base3\Usermanager\Permission::for($scope, $operation), $resource);
$names = static fn() => array_map(static fn($g) => $g->name, $manager->getGroups());
verify(in_array('development', $names(), true), 'Bootstrap developer matches exact login');
verify(in_array('administration', $names(), true), 'Native WordPress manage_options yields administration group');
verify($service->decide($permission('system','admin'))->isAllowed(), 'WordPress manage_options authorizes BASE3 system/admin');
verify($service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Demo authenticated default works');
verify($service->decide($permission('feature','globalchatbotlauncherfeature'))->isAllowed(), 'Global launcher default allows authenticated visitors');
verify($service->decide($permission('wordpress','edit_post', new \Base3\Authorization\Dto\ResourceReference('wordpress','post',55)))->isAllowed(), 'WordPress edit_post meta-capability uses native resource ID');
verify(!$service->decide($permission('wordpress','edit_post', new \Base3\Authorization\Dto\ResourceReference('wordpress','post',99)))->isAllowed(), 'Wrong resource ID denies');
verify(!$service->decide(new \Base3\Authorization\Dto\AuthorizationRequest('wordpress', \Base3\Usermanager\Permission::for('system','admin'), null, new \Base3\Authorization\Dto\SubjectReference('wordpress','user',2)))->isAllowed(), 'Delegated principal denied');
verify(!$service->decide(new \Base3\Authorization\Dto\AuthorizationRequest('other', \Base3\Usermanager\Permission::for('system','admin')))->isAllowed(), 'Unknown authority fails closed');
$GLOBALS['who'] = 2;
verify(!in_array('development', $names(), true), 'Ordinary native editor is not an implicit BASE3 developer');
verify(!$service->decide($permission('system','admin'))->isAllowed(), 'WordPress editor cannot administer system');
verify($service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Editor can use authenticated feature by default');
$rules = ['wordpressdemoaudiencefeature' => $editor->parse([['kind'=>'role','value'=>'editor']], array_map(static fn($g) => $g->name, $groups->getGroups()))];
$features->replaceAll($rules, array_map(static fn($g) => $g->name, $groups->getGroups()));
verify($service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Native role grants a feature without admin capability');
$GLOBALS['who'] = 1;
verify(!$service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Feature override excludes other roles');
$features->replaceAll(['wordpressdemoaudiencefeature'=>[]], array_map(static fn($g) => $g->name, $groups->getGroups()));
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Explicit empty feature override denies');
$criterionRule = $editor->parse([['kind'=>'criterion','value'=>'base3emaildomaincriterion','config'=>'{"domain":"qualitus.de"}']], array_map(static fn($g) => $g->name, $groups->getGroups()));
$features->replaceAll(['wordpressdemoaudiencefeature'=>$criterionRule], array_map(static fn($g) => $g->name, $groups->getGroups()));
$GLOBALS['who'] = 3;
verify($service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Discoverable email-domain criterion grants feature without native role');
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Unmatched email domain does not grant feature');
$nested = $editor->parse([['kind'=>'expression','config'=>'{"all":[{"group":"authenticated"},{"criterion":"base3emaildomaincriterion","config":{"domain":"qualitus.de"}}]}']], array_map(static fn($g) => $g->name, $groups->getGroups()));
verify($editor->describe($nested)[0]['kind'] === 'expression', 'Complex AND expression round-trips without flattening');
$features->replaceAll(['wordpressdemoaudiencefeature'=>$nested], array_map(static fn($g) => $g->name, $groups->getGroups()));
$GLOBALS['who'] = 3;
verify($service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Nested audience AND uses one evaluator');
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Nested audience requires both conditions');

$groups->create('reviewers','Review team');
$groups->replaceAll(['reviewers'=>$editor->parse([['kind'=>'role','value'=>'editor']],null)]);
verify(in_array('reviewers', $names(), true), 'Custom group membership uses the shared native role criterion');
$features->replaceAll(['wordpressdemoaudiencefeature'=>['group'=>'reviewers']], array_map(static fn($g) => $g->name, $groups->getGroups()));
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
verify(!$service->decide($permission('feature','globalchatbotlauncherfeature'))->isAllowed(), 'Canonical global launcher default denies anonymous visitors');
$features->replaceAll(['globalchatbotlauncherfeature' => []], array_map(static fn($g) => $g->name, $groups->getGroups()));
verify(!$service->decide($permission('feature','globalchatbotlauncherfeature'))->isAllowed(), 'Explicit global launcher deny applies to anonymous visitors');
$GLOBALS['who'] = 2;
verify(!$service->decide($permission('feature','globalchatbotlauncherfeature'))->isAllowed(), 'Explicit global launcher deny applies to authenticated visitors');
$GLOBALS['who'] = 0;
verify(!$service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Unauthenticated visitor denied against non-public feature audience');
echo 'WordPressAuthorizationSmokeTest OK' . PHP_EOL;

$GLOBALS['users'][4] = new WP_User(4, 'other-site-admin', 'admin@example.org', ['administrator'], ['manage_options'=>true,'read'=>true]);
$featureTab = $map->getInstanceByInterfaceName(\UiFoundation\Api\ISettingsTab::class, 'authorizationfeaturessettingstab');
$groupTab = $map->getInstanceByInterfaceName(\UiFoundation\Api\ISettingsTab::class, 'authorizationgroupssettingstab');
$GLOBALS['who'] = 4;
verify(in_array('development', $names(), true), 'Native WordPress administrator receives project development membership');
verify($featureTab->isVisible(), 'Native WordPress administrator can see generic Features without an allowlist');
verify($groupTab->getDisplayName() === 'authorizationgroupsdisplay', 'Shared group display is provided by generic settings discovery');
$audiences = new \Base3WordPress\Authorization\WordPressAudienceAdministrationService($service, $manager, $groups, $features, $editor);
verify(isset($audiences->getGroupView()['entries'][0]), 'Native WordPress administrator can open shared Groups without an allowlist');
verify(isset($audiences->getFeatureView()['rows'][0]), 'Native WordPress administrator can open shared Features without an allowlist');
$GLOBALS['who'] = 2;
$denied = false;
try { $audiences->getFeatureView(); } catch (\RuntimeException) { $denied = true; }
verify($denied, 'Native non-administrator cannot read feature audiences');
$GLOBALS['who'] = 1;
verify($featureTab->isVisible(), 'Code-owned developer can access generic feature settings');
verify($featureTab->getParentTabName() === 'base3settingstab', 'Feature editor is nested below canonical BASE3 root');
verify($featureTab->getDisplayName() === 'authorizationfeaturesdisplay', 'Generic features settings routes to shared display');
$groupView = $audiences->getGroupView();
$featureView = $audiences->getFeatureView();
verify(isset($groupView['criterionOptions'][0]['name']) && isset($featureView['rows'][0]['rules']), 'Shared UI receives exact group and feature data contracts');
$role = ['criterion'=>'wordpressrolecriterion', 'config'=>['roles'=>['editor']]];
$described = $editor->describe($role);
verify($described[0]['kind'] === 'criterion' && $editor->parse($described, null) === ['any'=>[$role]], 'WordPress role slugs round-trip through shared schema-backed criterion selector');
$session = new AudienceTestSession();
$_GET = [];
$_POST = [];
$display = new \ClientStack\Display\AuthorizationGroupsDisplay(
	$service, $audiences, \Base3\Core\Request::fromGlobals(), $session, new AudienceTestView(), new AudienceTestLinks(), new \Base3WordPress\Test\Support\AudienceTestAssetResolver()
);
$html = $display->getOutput('html', true);
verify(str_contains($html, 'data-base3-display="authorizationgroupsdisplay"') && str_contains($html, 'reviewers'), 'Shared ClientStack Groups template renders existing WordPress groups');
$_POST = ['base3_group_action'=>'create', 'base3_group_csrf'=>$session->get('authorization.groups.csrf'), 'base3_group_name'=>'webteam', 'base3_group_description'=>'Website team'];
$display = new \ClientStack\Display\AuthorizationGroupsDisplay(
	$service, $audiences, \Base3\Core\Request::fromGlobals(), $session, new AudienceTestView(), new AudienceTestLinks(), new \Base3WordPress\Test\Support\AudienceTestAssetResolver()
);
$createdHtml = $display->getOutput('html', true);
verify(str_contains($createdHtml, 'BASE3 group created.') && str_contains($createdHtml, 'webteam'), 'Shared CSRF-protected Groups display creates WordPress-managed group');
$audiences->replaceGroupRules(['webteam'=>[['kind'=>'criterion', 'value'=>'wordpressrolecriterion','config'=>'{"roles":["subscriber"]}']]], 'webteam');
verify($groups->getDefaultGroup() === 'webteam', 'Default group is stored with membership rules');
$GLOBALS['who'] = 3;
verify(in_array('webteam', $names(), true), 'Native subscriber group criterion applies through same WordPress usermanager');
$GLOBALS['who'] = 2;
verify(in_array('webteam', $names(), true), 'Authenticated WordPress user without another explicit group receives nonprivileged fallback');
$GLOBALS['who'] = 1;
$audiences->replaceGroupRules(['webteam'=>[['kind'=>'criterion','value'=>'wordpressrolecriterion','config'=>'{"roles":["subscriber"]}']]], '');
$GLOBALS['who'] = 2;
verify(!in_array('webteam', $names(), true), 'Unmatched WordPress roles do not join custom group when fallback is disabled');
$GLOBALS['who'] = 1;
$audiences->replaceGroupRules(['webteam'=>[['kind'=>'criterion','value'=>'wordpressrolecriterion','config'=>'{"roles":["subscriber"]}']]], 'webteam');
$GLOBALS['who'] = 1;
$disallowed = false;
try { $audiences->replaceGroupRules([], 'development'); } catch (InvalidArgumentException) { $disallowed = true; }
verify($disallowed, 'Privileged development group cannot be configured as fallback');
$disallowed = false;
try { $audiences->removeGroup('webteam'); } catch (InvalidArgumentException) { $disallowed = true; }
verify($disallowed, 'Managed fallback group cannot be removed while referenced');
$_POST = [];
$featureDisplay = new \ClientStack\Display\AuthorizationFeaturesDisplay(
	$service, $audiences, \Base3\Core\Request::fromGlobals(), $session, new AudienceTestView(), new AudienceTestLinks(), new \Base3WordPress\Test\Support\AudienceTestAssetResolver()
);
$featureHtml = $featureDisplay->getOutput('html', true);
verify(str_contains($featureHtml, 'data-base3-display="authorizationfeaturesdisplay"') && str_contains($featureHtml, 'wordpressdemoaudiencefeature'), 'Shared ClientStack Features template renders WordPress features');
$_POST = ['base3_feature_action'=>'save', 'base3_feature_csrf'=>$session->get('authorization.features.csrf'),
	'base3_feature_rules'=>[
		'wordpressdemoaudiencefeature'=>[['kind'=>'criterion', 'value'=>'wordpressrolecriterion', 'config'=>'{"roles":["editor"]}']],
		'authorizationgroupssettingstab'=>[['kind'=>'group', 'value'=>'administration']],
		'authorizationfeaturessettingstab'=>[['kind'=>'group', 'value'=>'development']]
	]];
$featureDisplay = new \ClientStack\Display\AuthorizationFeaturesDisplay(
	$service, $audiences, \Base3\Core\Request::fromGlobals(), $session, new AudienceTestView(), new AudienceTestLinks(), new \Base3WordPress\Test\Support\AudienceTestAssetResolver()
);
verify(str_contains($featureDisplay->getOutput('html', true), 'Feature audiences saved.'), 'Shared CSRF-protected Features display saves WordPress feature audience');
$GLOBALS['who'] = 2;
verify($service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Updated shared feature audience matches WordPress native role');
$GLOBALS['who'] = 1;
verify(!$service->decide($permission('feature','wordpressdemoaudiencefeature'))->isAllowed(), 'Updated shared feature audience excludes other roles');
$GLOBALS['who'] = 4;
$_POST = [];
http_response_code(200);
$display = new \ClientStack\Display\AuthorizationGroupsDisplay(
	$service, $audiences, \Base3\Core\Request::fromGlobals(), $session, new AudienceTestView(), new AudienceTestLinks(), new \Base3WordPress\Test\Support\AudienceTestAssetResolver()
);
$out4 = $display->getOutput('html', true);
verify(str_contains($out4, 'data-base3-display="authorizationgroupsdisplay"') && http_response_code() === 200,
	'Native WordPress administrator opens the direct shared Groups display');
$featureDisplay = new \ClientStack\Display\AuthorizationFeaturesDisplay(
	$service, $audiences, \Base3\Core\Request::fromGlobals(), $session, new AudienceTestView(), new AudienceTestLinks(), new \Base3WordPress\Test\Support\AudienceTestAssetResolver()
);
verify(str_contains($featureDisplay->getOutput('html', true), 'data-base3-display="authorizationfeaturesdisplay"'),
	'Native WordPress administrator opens the direct shared Features display');
$GLOBALS['who'] = 2;
$denied = false;
try { $audiences->getGroupView(); } catch (\RuntimeException) { $denied = true; }
verify($denied, 'Native WordPress editor without manage_options cannot edit audiences');
verify(!in_array('development', $names(), true), 'Native WordPress editor never receives project development membership');

verify(!is_file(dirname(__DIR__, 3) . '/Base3WordPressLab/src/Feature/GlobalChatbotLauncherFeature.php'),
	'Removed WordPressLab launcher feature cannot shadow the canonical ClientStack feature');
echo 'WordPress project navigation and display tests OK' . PHP_EOL;

ob_end_flush();
