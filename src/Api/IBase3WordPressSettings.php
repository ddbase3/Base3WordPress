<?php declare(strict_types=1);

namespace Base3WordPress\Api;

/** Administration tab definitions for the native WordPress BASE3 tools page. */
interface IBase3WordPressSettings {

	/** @return array<int,array<string,mixed>> */
	public function getAdministrationConfig(): array;
}
