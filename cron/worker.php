<?php declare(strict_types=1);

// Standalone WordPress CLI bootstrap. One fresh PHP process runs one BASE3 worker cycle.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit(64);
}

$root = getenv('BASE3_WORKER_DOCUMENT_ROOT');
if (!is_string($root) || $root === '') {
	// Normal native deployment: modules/Base3WordPress/cron inside the WordPress plugin.
	$root = dirname(__DIR__, 6);
}
$root = realpath($root);
if ($root === false || !is_file($root . '/wp-load.php')) {
	fwrite(STDERR, "BASE3 worker: WordPress root or wp-load.php not found.\n");
	exit(66);
}

$host = getenv('BASE3_WORKER_HOST');
if (is_string($host) && $host !== '') {
	if (preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]+)?\z/D', $host) !== 1) {
		fwrite(STDERR, "BASE3 worker: invalid BASE3_WORKER_HOST.\n");
		exit(64);
	}
	$_SERVER['HTTP_HOST'] = $host;
	$_SERVER['SERVER_NAME'] = explode(':', $host, 2)[0];
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

chdir($root);
// Keep this BASE3-only CLI cycle from spawning WordPress cron tasks.
if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);

try {
	require_once $root . '/wp-load.php';
	if (!class_exists(\Base3WordPressBridge\Runtime::class)) {
		throw new \RuntimeException('BASE3 WordPress native integration is not active.');
	}
	\Base3WordPressBridge\Runtime::runWorker();
} catch (\Throwable $error) {
	fwrite(STDERR, "BASE3 worker: " . $error->getMessage() . "\n");
	exit(1);
}
