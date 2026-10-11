<?php declare(strict_types=1);

namespace Base3WordPress;

use Base3\Api\IAssetResolver;
use Base3\Api\IClassMap;
use Base3\Api\IContainer;
use Base3\Api\IModuleRegistry;
use Base3\Api\IMvcView;
use Base3\Api\IPlugin;
use Base3\Api\IBase;
use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\ConfigValue\Resolver\ConfigValueResolver;
use Base3\Database\Api\IDatabase;
use Base3\Event\Api\IEventManager;
use Base3\Event\EventManager;
use Base3\Language\Api\ILanguage;
use Base3\Session\Api\ISession;
use Base3\Translation\Api\ITranslation;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Worker\DelegateWorker;
use Base3WordPress\Adapter\WordPressAccesscontrol;
use Base3WordPress\Adapter\WordPressAssetResolver;
use Base3WordPress\Adapter\WordPressConfiguration;
use Base3WordPress\Adapter\WordPressDatabase;
use Base3WordPress\Adapter\WordPressFileStorageFactory;
use Base3WordPress\Adapter\WordPressLanguage;
use Base3WordPress\Adapter\WordPressManagedFileStorageService;
use Base3WordPress\Adapter\WordPressMvcView;
use Base3WordPress\Adapter\WordPressSession;
use Base3WordPress\Adapter\WordPressTranslation;
use Base3WordPress\Adapter\WordPressUsermanager;
use ResourceFoundation\Api\IFileStorageFactory;
use ResourceFoundation\Api\IManagedFileStorageService;

/** Host-backed BASE3 service contracts; final runtime policies belong to the project module. */
final class Base3WordPressPlugin implements IPlugin, IBase {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string { return 'base3wordpressplugin'; }

	public function init(): void {
		$c = $this->container;
		$c->set(self::getName(), $this, IContainer::SHARED)
			->set(IConfiguration::class, fn() => new WordPressConfiguration(), IContainer::SHARED)
			->set('configuration', IConfiguration::class, IContainer::ALIAS)
			->set(IDatabase::class, fn() => new WordPressDatabase(), IContainer::SHARED)
			->set('database', IDatabase::class, IContainer::ALIAS)
			->set(ISession::class, fn() => new WordPressSession(), IContainer::SHARED)
			->set('session', ISession::class, IContainer::ALIAS)
			->set(IAccesscontrol::class, fn() => new WordPressAccesscontrol(), IContainer::SHARED)
			->set('accesscontrol', IAccesscontrol::class, IContainer::ALIAS)
			->set(IUsermanager::class, fn() => new WordPressUsermanager(), IContainer::SHARED)
			->set('usermanager', IUsermanager::class, IContainer::ALIAS)
			->set(ILanguage::class, fn() => new WordPressLanguage(), IContainer::SHARED)
			->set(ITranslation::class, fn($c) => new WordPressTranslation($c->get(ILanguage::class), $c->get(IModuleRegistry::class)), IContainer::SHARED)
			->set(IMvcView::class, fn($c) => new WordPressMvcView($c->get(ILanguage::class), $c->get(IModuleRegistry::class)))
			->set('view', IMvcView::class, IContainer::ALIAS)
			->set(IAssetResolver::class, fn($c) => new WordPressAssetResolver($c->get(IModuleRegistry::class)), IContainer::SHARED)
			->set(IEventManager::class, fn() => new EventManager(), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(IConfigValueResolver::class, fn($c) => new ConfigValueResolver($c->get(IClassMap::class)), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(IFileStorageFactory::class, fn() => new WordPressFileStorageFactory(), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(IManagedFileStorageService::class, fn($c) => new WordPressManagedFileStorageService($c->get(IFileStorageFactory::class)), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set('workers', fn($c) => [
				'Base3WordPress' => fn() => new DelegateWorker($c->get(IClassMap::class), $c->get(IConfiguration::class))
			], IContainer::SHARED | IContainer::NOOVERWRITE);
	}
}
