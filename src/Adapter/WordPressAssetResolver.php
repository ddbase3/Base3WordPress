<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Api\IAssetResolver;
use Base3\Api\IModuleRegistry;

/** Resolves logical BASE3 assets from their actual owning WordPress plugin. */
final class WordPressAssetResolver implements IAssetResolver {

	public function __construct(private readonly IModuleRegistry $modules) {}

	public function resolve(string $path): string {
		if (!preg_match('~^plugin/([^/]+)/assets/(.+)$~', $path, $matches)) return $path;
		$root = $this->modules->getModulePath($matches[1]);
		if ($root === null) return $path;
		$subpath = $matches[2];
		if (str_contains($subpath, '\\') || in_array('..', explode('/', $subpath), true)) return $path;
		$assetRoot = realpath($root . '/assets');
		$file = realpath($root . '/assets/' . $subpath);
		if ($assetRoot === false || $file === false || !str_starts_with($file, $assetRoot . '/') || !is_file($file)) return $path;
		return add_query_arg(
			'v',
			substr(hash_file('sha256', $file), 0, 12),
			plugins_url('assets/' . $subpath, $root . '/base3.json')
		);
	}
}
