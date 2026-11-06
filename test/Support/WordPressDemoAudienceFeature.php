<?php declare(strict_types=1);

namespace Base3WordPress\Test\Support;

use Base3\Authorization\Api\IFeature;
use Base3\Usermanager\Permission;

/** Isolated audience feature used to verify WordPress host authorization. */
final class WordPressDemoAudienceFeature implements IFeature {

	public static function getName(): string { return 'wordpressdemoaudiencefeature'; }
	public function getLabel(): string { return 'WordPress Demo'; }
	public function getDescription(): string { return 'Audience test fixture'; }
	public function getPermissions(): array { return [Permission::for('feature', self::getName())]; }
	public function getDefaultAudience(): array { return ['group' => 'authenticated']; }
}
