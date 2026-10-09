<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Api\ISystemService;

final class WordPressSystemService implements ISystemService {

	public function getHostSystemName(): string { return 'WordPress'; }
	public function getHostSystemVersion(): string { return (string) get_bloginfo('version'); }
	public function getEmbeddedSystemName(): string { return 'BASE3'; }
	public function getEmbeddedSystemVersion(): string {
		return (string) (json_decode((string) file_get_contents(DIR_ROOT . 'base3.json'), true)['version'] ?? '');
	}
}
