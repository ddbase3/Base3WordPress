<?php declare(strict_types=1);

namespace Base3WordPress\Group;

use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;

/** Anonymous BASE3 group: host identity is absent, never a WordPress native role. */
final class Base3WordPressAnonymousGroup implements IGroup {
	public static function getName(): string { return 'base3wordpressanonymousgroup'; }
	public function getGroup(): Group { return Group::fromArray(['id'=>'anonymous','name'=>'anonymous','info'=>'Unauthenticated visitors','roles'=>[]]); }
	public function getCriteria(): array { return ['criterion'=>'base3anonymouscriterion','config'=>[]]; }
	public function isEditable(): bool { return false; }
}
