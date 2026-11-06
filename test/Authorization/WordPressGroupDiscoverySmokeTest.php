<?php declare(strict_types=1);

// Scan the real distributed module classmap, not a reduced authorization fixture.
$modulesRoot = dirname(__DIR__, 3);
$pluginRoot = dirname($modulesRoot);
$temporaryFile = tempnam(sys_get_temp_dir(), 'base3-wp-group-');
if ($temporaryFile === false) throw new RuntimeException('Cannot allocate classmap test directory.');
unlink($temporaryFile);
if (!mkdir($temporaryFile, 0700)) throw new RuntimeException('Cannot create classmap test directory.');

foreach ([
	'DIR_ROOT' => $modulesRoot . '/Base3Framework/',
	'DIR_FRAMEWORK' => $modulesRoot . '/Base3Framework/',
	'DIR_SRC' => $modulesRoot . '/Base3Framework/src/',
	'DIR_PLUGIN' => $modulesRoot . '/',
	'DIR_BASE3' => $modulesRoot . '/',
	'DIR_TEST' => $modulesRoot . '/Base3Framework/test/',
	'DIR_TPL' => $modulesRoot . '/Base3Framework/tpl/',
	'DIR_CNF' => $temporaryFile . '/',
	'DIR_TMP' => $temporaryFile . '/',
	'DIR_LOCAL' => $temporaryFile . '/',
	'DIR_USERFILES' => $temporaryFile . '/'
] as $constant => $value) {
	if (defined($constant)) throw new RuntimeException('Run this test in a separate CLI process: ' . $constant);
	define($constant, $value);
}

try {
	require_once DIR_SRC . 'Core/Autoloader.php';
	\Base3\Core\Autoloader::register();
	foreach (['WordPressModuleRoots', 'ModuleCatalog', 'ModuleAutoloader', 'WordPressClassMap'] as $name) {
		require_once $pluginRoot . '/includes/' . $name . '.php';
	}
	$catalog = new \Base3WordPressBridge\ModuleCatalog($modulesRoot);
	\Base3WordPressBridge\ModuleAutoloader::register($catalog);
	$container = new \Base3\Core\ServiceLocator();
	$container->set(\Base3\Api\IContainer::class, $container, \Base3\Api\IContainer::SHARED);
	$classmap = new \Base3WordPressBridge\WordPressClassMap($container, $catalog);
	$container->set(\Base3\Api\IClassMap::class, $classmap, \Base3\Api\IContainer::SHARED);
	$classmap->generate(true);

	$owners = [];
	foreach ($classmap->getInstancesByInterface(\Base3\Usermanager\Api\IGroup::class) as $definition) {
		if (!$definition instanceof \Base3\Usermanager\Api\IGroup) continue;
		$name = (string)$definition->getGroup()->name;
		if (isset($owners[$name])) {
			throw new RuntimeException('Duplicate BASE3 group ' . $name . ': ' . $owners[$name] . ' / ' . $definition::class);
		}
		$owners[$name] = $definition::class;
	}
	foreach (['anonymous', 'authenticated', 'administration', 'development'] as $required) {
		if (!isset($owners[$required])) throw new RuntimeException('Missing BASE3 group ' . $required);
	}
	// Exercise the WordPress owner with the same actual classmap to catch duplicate group IDs.
	$settings = new \Base3\Settings\Json\JsonSettingsStore(new \Base3\Configuration\ArrayConfiguration([
		'directories' => ['data' => $temporaryFile]
	]));
	$evaluator = new \Base3\Usermanager\UserCriterionEvaluator($classmap);
	$features = new \Base3WordPress\Service\WordPressFeatureConfiguration($settings, $classmap, $evaluator);
	$groups = new \Base3WordPress\Service\WordPressGroupConfiguration($settings, $classmap, $evaluator, $features);
	if (count($groups->getEntries()) !== count($owners)) {
		throw new RuntimeException('WordPress group catalog does not match discovered group definitions.');
	}
	if ($owners['anonymous'] !== \Base3\Usermanager\Definition\Base3AnonymousGroup::class
		|| $owners['authenticated'] !== \Base3\Usermanager\Definition\Base3AuthenticatedGroup::class) {
		throw new RuntimeException('Built-in groups must be owned by Base3Framework.');
	}
	echo 'WordPress group discovery: unique built-in, administration and development groups verified.' . PHP_EOL;
} finally {
	foreach (['classmap.php', 'ctorcache.php'] as $filename) {
		if (is_file($temporaryFile . '/' . $filename)) unlink($temporaryFile . '/' . $filename);
	}
	rmdir($temporaryFile);
}
