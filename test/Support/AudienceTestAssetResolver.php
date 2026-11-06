<?php declare(strict_types=1);

namespace Base3WordPress\Test\Support;

use Base3\Api\IAssetResolver;

final class AudienceTestAssetResolver implements IAssetResolver {
	public function resolve(string $path): string { return '/assets/' . ltrim($path, '/'); }
}
