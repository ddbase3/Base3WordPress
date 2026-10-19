<?php declare(strict_types=1);

namespace Base3WordPress\Group;

use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;

/** WordPress administration baseline plus optional, non-native BASE3 membership additions. */
final class Base3WordPressAdministrationGroup implements IGroup {
	public static function getName(): string { return 'base3wordpressadministrationgroup'; }
	public function getGroup(): Group { return Group::fromArray(['id'=>'administration','name'=>'administration','info'=>'Native WordPress managers and configured BASE3 additions','roles'=>[]]); }
	public function getCriteria(): array { return ['criterion'=>'wordpresscapabilitycriterion','config'=>['capability'=>'manage_options']]; }
	public function isEditable(): bool { return true; }
}
