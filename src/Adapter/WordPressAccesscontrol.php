<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Accesscontrol\Api\IAccesscontrol;

final class WordPressAccesscontrol implements IAccesscontrol {

	public function getUserId() { return get_current_user_id(); }

	public function authenticate(): void {
		// WordPress validates its auth cookies before init and before dispatching REST/AJAX.
	}
}
