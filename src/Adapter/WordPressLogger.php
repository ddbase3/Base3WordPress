<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Logger\AbstractLogger;

/** Logs to the standard PHP/WordPress error log without a second logging pipeline. */
final class WordPressLogger extends AbstractLogger {

	public function logLevel(string $level, string|\Stringable $message, array $context = []): void {
		$scope = preg_replace('/[^a-z0-9_.-]/i', '_', (string) ($context['scope'] ?? 'default'));
		$line = '[BASE3][' . strtoupper($level) . '][' . $scope . '] ' . (string) $message;
		if ($context !== []) {
			$dump = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
			if (is_string($dump)) $line .= ' ' . $dump;
		}
		error_log($line);
	}
}
