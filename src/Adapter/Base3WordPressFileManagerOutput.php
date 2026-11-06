<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Translation\Api\ITranslation;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use ClientStack\FileManager\FileManagerHttpService;
use ResourceFoundation\Api\IManagedFileStorageService;

/** Authorizes WordPress FileManager requests before delegating to ClientStack. */
final class Base3WordPressFileManagerOutput implements IOutput {

	private const MAX_FILE_SIZE = 50 * 1024 * 1024;

	public function __construct(
		private readonly IRequest $request,
		private readonly IUsermanager $usermanager,
		private readonly IAuthorizationService $authorization,
		private readonly ITranslation $translation,
		private readonly FileManagerHttpService $fileManagerHttpService,
		private readonly array $wordpressFileManagerOwner
	) {}

	public static function getName(): string {
		return 'base3wordpressfilemanageroutput';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'json') {
			return $this->errorResponse(404, 'invalid_request');
		}
		if (!$this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')
		))->isAllowed() || check_ajax_referer('base3_admin_display', '_wpnonce', false) === false) {
			return $this->errorResponse(403, 'access_denied');
		}

		$userId = (string)($this->usermanager->getUser()?->id ?? '');
		if ($userId === '' || !ctype_digit($userId) || (int)$userId <= 0) {
			return $this->errorResponse(403, 'access_denied');
		}

		$ownerGroup = $this->wordpressFileManagerOwner['group'] ?? null;
		$ownerName = $this->wordpressFileManagerOwner['name'] ?? null;
		if (!is_string($ownerGroup) || trim($ownerGroup) === ''
			|| !is_string($ownerName) || trim($ownerName) === '') {
			return $this->errorResponse(503, 'owner_unavailable');
		}

		$action = $this->request->get('fm_action', '');
		return $this->fileManagerHttpService->handle(
			is_string($action) ? trim($action) : '',
			$ownerGroup,
			$ownerName,
			IManagedFileStorageService::MODE_COLLECTION,
			$userId,
			self::MAX_FILE_SIZE
		);
	}

	private function errorResponse(int $status, string $key): string {
		http_response_code($status);
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
		}
		return json_encode(['message' => $this->translation->translate('FileManager', 'base3wordpress', $key)], JSON_THROW_ON_ERROR);
	}
}
