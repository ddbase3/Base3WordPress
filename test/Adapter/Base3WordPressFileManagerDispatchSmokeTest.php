<?php declare(strict_types=1);

// Standalone, isolated dispatch smoke test. Run with PHP CLI (no WordPress bootstrap needed).
namespace Base3\Api {
	interface IOutput {
		public static function getName(): string;
		public function getOutput(string $out = 'html', bool $final = false): string;
	}
	interface IRequest {
		public function get(string $key, $default = null);
	}
	interface IDisplay extends IOutput {
		public function setData($data);
	}
	interface IClassMap {
		public function getInstanceByInterfaceName($interface, $name, $retry = false);
	}
}

namespace Base3\Accesscontrol\Api {
	interface IAccesscontrol { public function authenticate(): void; }
}

namespace Base3\Usermanager {
	final class Permission {
		public function __construct(public string $scope, public string $permission) {}
		public static function for(string $scope, string $permission, mixed $target = null): self {
			return new self($scope, $permission);
		}
	}
}

namespace Base3\Usermanager\Api {
	interface IUsermanager {
		public function can(\Base3\Usermanager\Permission $permission): bool;
		public function getUser(): ?object;
	}
}

namespace Base3\Translation\Api {
	interface ITranslation {
		public function translate(string $scope, string $domain, string $key, string $fallback): string;
	}
}

namespace Base3\LinkTarget\Api {
	interface ILinkTargetService {
		public function getLink(array $target, array $params = []): string;
	}
}

namespace Base3WordPress\Api {
	interface IBase3WordPressSettings {
		public function getAdministrationConfig(): array;
	}
}

namespace Base3WordPress\Adapter {
	function check_ajax_referer(string $nonceAction, string $queryArg, bool $die = true): int|false {
		$GLOBALS['nonce_checks'][] = [$nonceAction, $queryArg, $die];
		return ($GLOBALS['nonce_valid'] ?? false) ? 1 : false;
	}

	final class WordPressFileManagerHttpService {
		public static array $calls = [];
		public function handle(string $action, string $group, string $name, int $userId, int $maxFileSize): never {
			self::$calls[] = compact('action', 'group', 'name', 'userId', 'maxFileSize');
			throw new \RuntimeException('FileManager handler invoked');
		}
	}
}

namespace Base3WordPressBridge {
	final class TestContainer {
		public function __construct(public object $map, public object $auth) {}
		public function get(string $key): object {
			return $key === \Base3\Api\IClassMap::class ? $this->map : $this->auth;
		}
	}
	final class Runtime {
		public static ?TestContainer $container = null;
		public static int $finishes = 0;
		public static function boot(): void {}
		public static function container(): TestContainer { return self::$container; }
		public static function finish(): void { self::$finishes++; }
	}
	final class DummyMap implements \Base3\Api\IClassMap {
		public array $calls = [];
		public function getInstanceByInterfaceName($interface, $name, $retry = false) {
			$this->calls[] = [$interface, $name];
			return $interface === \Base3\Api\IOutput::class ? new DummyOutput() : null;
		}
	}
	final class DummyOutput implements \Base3\Api\IOutput {
		public static function getName(): string { return 'base3wordpressfilemanageroutput'; }
		public function getOutput(string $out = 'html', bool $final = false): string {
			http_response_code(403);
			return '{"message":"denied"}';
		}
	}
	final class DummyAuth implements \Base3\Accesscontrol\Api\IAccesscontrol {
		public function authenticate(): void {}
	}
}

namespace {
	function wp_unslash(mixed $value): mixed { return $value; }
	function wp_die(string $message, string $title, array $args): never {
		throw new \RuntimeException('wp_die response=' . ($args['response'] ?? 0));
	}
	use Base3\Api\IRequest;
	use Base3\LinkTarget\Api\ILinkTargetService;
	use Base3\Translation\Api\ITranslation;
	use Base3\Usermanager\Api\IUsermanager;
	use Base3\Usermanager\Permission;
	use Base3WordPress\Adapter\Base3WordPressFileManagerOutput;
	use Base3WordPress\Adapter\WordPressFileManagerHttpService;
	use Base3WordPress\Api\IBase3WordPressSettings;
	use Base3WordPressLab\Service\Base3WordPressLabSettings;

	require_once __DIR__ . '/../../src/Adapter/Base3WordPressFileManagerOutput.php';
	require_once __DIR__ . '/../../../Base3WordPressLab/src/Service/Base3WordPressLabSettings.php';
	require_once __DIR__ . '/../../../../includes/AjaxBridge.php';

	ob_start();

	function verify(bool $result, string $message): void {
		if (!$result) throw new \RuntimeException('FAILED: ' . $message);
		echo 'PASS: ' . $message . PHP_EOL;
	}

	final class DummyRequest implements IRequest {
		public function __construct(private array $query) {}
		public function get(string $key, $default = null) { return $this->query[$key] ?? $default; }
	}

	final class DummyUsermanager implements IUsermanager {
		public function __construct(private bool $admin, private ?object $user) {}
		public function can(Permission $permission): bool {
			return $this->admin && $permission->scope === 'system' && $permission->permission === 'admin';
		}
		public function getUser(): ?object { return $this->user; }
	}

	final class DummyTranslation implements ITranslation {
		public function translate(string $scope, string $domain, string $key, string $fallback): string { return $fallback; }
	}

	final class DummyLinks implements ILinkTargetService {
		public array $calls = [];
		public function getLink(array $target, array $params = []): string {
			$this->calls[] = ['target' => $target, 'params' => $params];
			return 'admin-ajax.php?action=base3_dispatch&name=' . $target['name']
				. '&out=' . $target['out'] . '&fm_action=' . $params['fm_action'];
		}
	}

	final class DummySettings implements IBase3WordPressSettings {
		public function __construct(private array $data) {}
		public function getAdministrationConfig(): array { return $this->data; }
	}

	$links = new DummyLinks();
	$lab = new Base3WordPressLabSettings(new DummyTranslation(), $links);
	$tabs = $lab->getAdministrationConfig();
	$displayData = null;
	foreach ($tabs as $tab) {
		foreach (($tab['displays'] ?? []) as $display) {
			if (($display['name'] ?? '') === 'chatbotconfigdisplay') $displayData = $display['data'] ?? null;
		}
	}
	verify(is_array($displayData), 'project settings contain the chatbot display configuration');
	verify(($displayData['group'] ?? null) === 'chatbot' && ($displayData['name'] ?? null) === 'default', 'storage owner is preserved');
	$actions = ['list', 'stat', 'mkdir', 'delete', 'rmdir', 'copy', 'move', 'uploadStart', 'uploadChunk', 'uploadStatus', 'uploadFinish', 'uploadAbort', 'download'];
	verify(count($displayData['resources']['endpoints'] ?? []) === count($actions), 'all 13 FileManager actions have endpoints');
	foreach ($actions as $action) {
		$url = $displayData['resources']['endpoints'][$action] ?? '';
		verify(str_contains($url, 'action=base3_dispatch')
			&& str_contains($url, 'name=base3wordpressfilemanageroutput')
			&& str_contains($url, 'fm_action=' . $action)
			&& !str_contains($url, 'action=base3_filemanager'), 'generic endpoint for ' . $action);
	}
	verify(Base3WordPressFileManagerOutput::getName() === strtolower((new \ReflectionClass(Base3WordPressFileManagerOutput::class))->getShortName()), 'BASE3 class-map naming contract');

	$service = new WordPressFileManagerHttpService();
	$request = new DummyRequest(['fm_action' => 'uploadChunk']);
	$settings = new DummySettings($tabs);
	$GLOBALS['nonce_valid'] = true;
	$GLOBALS['nonce_checks'] = [];
	$output = new Base3WordPressFileManagerOutput($request, new DummyUsermanager(false, (object) ['id' => 1]), $settings, $service);
	http_response_code(200);
	verify(json_decode($output->getOutput('json', true), true) === ['message' => 'BASE3 access permission or nonce missing.'] && http_response_code() === 403, 'non-administrator is denied');
	verify($GLOBALS['nonce_checks'] === [], 'permission is checked before nonce');

	$GLOBALS['nonce_valid'] = false;
	$output = new Base3WordPressFileManagerOutput($request, new DummyUsermanager(true, (object) ['id' => 1]), $settings, $service);
	http_response_code(200);
	verify(json_decode($output->getOutput('json', true), true) === ['message' => 'BASE3 access permission or nonce missing.'] && http_response_code() === 403, 'invalid nonce is denied');
	verify(count($GLOBALS['nonce_checks']) === 1 && $GLOBALS['nonce_checks'][0] === ['base3_admin_display', '_wpnonce', false], 'same WordPress nonce contract');

	$GLOBALS['nonce_valid'] = true;
	$output = new Base3WordPressFileManagerOutput($request, new DummyUsermanager(true, null), $settings, $service);
	http_response_code(200);
	verify(json_decode($output->getOutput('json', true), true) === ['message' => 'BASE3 access permission or nonce missing.'] && http_response_code() === 403, 'missing user identity is denied');

	$output = new Base3WordPressFileManagerOutput($request, new DummyUsermanager(true, (object) ['id' => 42]), new DummySettings([]), $service);
	$invalidOwner = false;
	try { $output->getOutput('json', true); } catch (\RuntimeException $error) { $invalidOwner = $error->getMessage() === 'Chatbot administration storage owner is not configured.'; }
	verify($invalidOwner, 'missing configured storage owner is rejected');

	$output = new Base3WordPressFileManagerOutput($request, new DummyUsermanager(true, (object) ['id' => 42]), $settings, $service);
	$called = false;
	try { $output->getOutput('json', true); } catch (\RuntimeException $error) { $called = $error->getMessage() === 'FileManager handler invoked'; }
	verify($called, 'authorized request reaches existing HTTP service');
	verify(WordPressFileManagerHttpService::$calls === [[
		'action' => 'uploadChunk', 'group' => 'chatbot', 'name' => 'default', 'userId' => 42, 'maxFileSize' => 50 * 1024 * 1024
	]], 'handler receives original action, storage owner and user id');

	$map = new \Base3WordPressBridge\DummyMap();
	\Base3WordPressBridge\Runtime::$container = new \Base3WordPressBridge\TestContainer($map, new \Base3WordPressBridge\DummyAuth());
	$_GET = ['action' => 'base3_dispatch', 'name' => 'base3wordpressfilemanageroutput', 'out' => 'json'];
	$_REQUEST = $_GET;
	http_response_code(200);
	ob_start();
	$finishedWith403 = false;
	try { \Base3WordPressBridge\AjaxBridge::dispatch(); }
	catch (\RuntimeException $error) { $finishedWith403 = $error->getMessage() === 'wp_die response=403'; }
	$body = ob_get_clean();
	verify($finishedWith403 && $body === '{"message":"denied"}', 'generic AJAX dispatch retains output HTTP status');
	verify($map->calls === [[\Base3\Api\IDisplay::class, 'base3wordpressfilemanageroutput'], [\Base3\Api\IOutput::class, 'base3wordpressfilemanageroutput']], 'generic AJAX resolves discovered IOutput');
	verify(\Base3WordPressBridge\Runtime::$finishes === 1, 'generic AJAX completes framework runtime');

	echo 'PASS: FileManager dispatch smoke test complete' . PHP_EOL;
	$results = ob_get_clean();
	echo $results;
}
