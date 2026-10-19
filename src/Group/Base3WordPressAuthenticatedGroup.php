<?php declare(strict_types=1);

namespace Base3WordPress\Group;

use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;

/** Authenticated BASE3 group: host session is authoritative. */
final class Base3WordPressAuthenticatedGroup implements IGroup {
	public static function getName(): string { return 'base3wordpressauthenticatedgroup'; }
	public function getGroup(): Group { return Group::fromArray(['id'=>'authenticated','name'=>'authenticated','info'=>'Logged-in WordPress users','roles'=>[]]); }
	public function getCriteria(): array { return ['criterion'=>'base3authenticatedcriterion','config'=>[]]; }
	public function isEditable(): bool { return false; }
}
