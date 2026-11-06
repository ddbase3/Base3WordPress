<?php declare(strict_types=1);

// Real ResourceFoundation/ClientStack storage integration, isolated from WordPress and its database.
$modules = dirname(__DIR__, 3);
foreach ([
	'ResourceFoundation/src/Api/IFileStorage.php',
	'ResourceFoundation/src/Api/IFileStorageFactory.php',
	'ResourceFoundation/src/Api/IManagedFileStorageService.php',
	'ClientStack/src/FileManager/FileManagerHttpService.php',
	'ClientStack/src/FileManager/FileUploadService.php',
	'Base3WordPress/src/Adapter/WordPressFileStorage.php',
	'Base3WordPress/src/Adapter/WordPressFileStorageFactory.php',
	'Base3WordPress/src/Adapter/WordPressManagedFileStorageService.php'
] as $file) {
	require_once $modules . '/' . $file;
}

$root = sys_get_temp_dir() . '/base3-wordpress-filemanager-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700)) throw new RuntimeException('Could not create test storage root.');
define('DIR_LOCAL', $root . '/');
$assert = static function(bool $result, string $message): void {
	if (!$result) throw new RuntimeException($message);
};

try {
	$service = new \Base3WordPress\Adapter\WordPressManagedFileStorageService(
		new \Base3WordPress\Adapter\WordPressFileStorageFactory()
	);
	$storage = $service->openOrCreate('chatbot', 'default', 'collection');
	$assert($storage->mkdir('docs'), 'Folder creation failed.');
	$assert($storage->write('docs/example.pdf', '%PDF-1.7'), 'File creation failed.');
	$assert(($storage->stat('docs')['type'] ?? '') === 'dir', 'Directory metadata must use dir.');
	$assert(($storage->stat('docs/example.pdf')['type'] ?? '') === 'file', 'File metadata must use file.');
	$descriptor = \ClientStack\FileManager\FileManagerHttpService::descriptor('docs', 'docs', $storage->stat('docs'));
	$assert($descriptor['kind'] === 'directory', 'Shared ClientStack descriptor must accept WordPress folder metadata.');
	$assert($storage->read('docs/example.pdf') === '%PDF-1.7', 'Existing contents must remain unchanged.');

	$service->copyOwner('chatbot', 'default', 'chatbot', 'copy', 'collection');
	$copy = $service->openOrCreate('chatbot', 'copy', 'collection');
	$assert($copy->read('docs/example.pdf') === '%PDF-1.7', 'Managed-storage owner copy failed.');

	$uploads = new \ClientStack\FileManager\FileUploadService($service, DIR_LOCAL . 'filemanager-upload/');
	$session = $uploads->start('chatbot', 'default', 'collection', '42', 50 * 1024 * 1024, [
		'name' => 'document.pdf', 'path' => 'docs', 'size' => 4, 'chunkSize' => 4, 'totalChunks' => 1
	]);
	$assert(is_string($session['uploadId'] ?? null), 'Shared upload session did not start.');
	$meta = $uploads->status('chatbot', 'default', 'collection', '42', $session['uploadId']);
	$assert($meta['uploadedChunks'] === [], 'Upload status must begin empty.');
	$assert(is_dir(DIR_LOCAL . 'filemanager-upload'), 'Existing WordPress upload temp root changed.');
	$uploads->abort('chatbot', 'default', 'collection', '42', $session['uploadId']);
	$assert($storage->read('docs/example.pdf') === '%PDF-1.7', 'Upload session modified existing files.');
	$assert(count(\ClientStack\FileManager\FileManagerHttpService::buildEndpoints('admin-ajax.php?action=base3_dispatch')) === 13, 'Endpoint count changed.');
	print "WordPressClientStackStorageSmokeTest OK: owner copy, descriptor, original file data, chunk session, 13 endpoints.\n";
} finally {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $item) {
		if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
		else unlink($item->getPathname());
	}
	rmdir($root);
}
