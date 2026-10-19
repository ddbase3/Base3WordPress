<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Permission;
use Base3WordPress\Api\IBase3WordPressSettings;
use RuntimeException;

/** Routes the existing WordPress FileManager HTTP service through standard BASE3 output dispatch. */
final class Base3WordPressFileManagerOutput implements IOutput {

	private const MAX_FILE_SIZE = 50 * 1024 * 1024;

	public function __construct(
		private readonly IRequest $request,
		private readonly IUsermanager $usermanager,
		private readonly IAuthorizationService $authorization,
		private readonly IBase3WordPressSettings $settings,
		private readonly WordPressFileManagerHttpService $fileManagerHttpService
	) {}

	public static function getName(): string {
		return 'base3wordpressfilemanageroutput';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if (!$this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')
		))->isAllowed()
			|| check_ajax_referer('base3_admin_display', '_wpnonce', false) === false) {
			return $this->errorResponse(403, 'BASE3 access permission or nonce missing.');
		}

		$userId = (int) ($this->usermanager->getUser()?->id ?? 0);
		if ($userId <= 0) {
			return $this->errorResponse(403, 'BASE3 access permission or nonce missing.');
		}

		[$group, $name] = $this->configuredOwner();
		$action = $this->request->get('fm_action', '');
		$this->fileManagerHttpService->handle(
			is_scalar($action) ? trim((string) $action) : '',
			$group,
			$name,
			$userId,
			self::MAX_FILE_SIZE
		);
	}

	/** @return array{0:string,1:string} */
	private function configuredOwner(): array {
		$config = null;
		foreach ($this->settings->getAdministrationConfig() as $tab) {
			foreach ($tab['displays'] ?? [] as $entry) {
				if (($entry['name'] ?? '') !== 'chatbotconfigdisplay') continue;
				if ($config !== null) throw new RuntimeException('Duplicate chatbot administration configuration.');
				$config = $entry['data'] ?? null;
			}
		}

		$group = is_array($config) ? trim((string) ($config['group'] ?? '')) : '';
		$name = is_array($config) ? trim((string) ($config['name'] ?? '')) : '';
		if ($group === '' || $name === '') {
			throw new RuntimeException('Chatbot administration storage owner is not configured.');
		}
		return [$group, $name];
	}

	private function errorResponse(int $status, string $message): string {
		http_response_code($status);
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
		}
		return json_encode(['message' => $message], JSON_THROW_ON_ERROR);
	}
}
