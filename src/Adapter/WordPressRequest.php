<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Core\Request;

/** Maps WordPress's reserved AJAX action parameter to the BASE3 request contract. */
final class WordPressRequest extends Request {

	public function initFromGlobals(): void {
		parent::initFromGlobals();
		$viaGet = ($this->get['action'] ?? null) === 'base3_dispatch';
		$viaPost = ($this->post['action'] ?? null) === 'base3_dispatch';
		if (!$viaGet && !$viaPost) return;

		// WordPress owns the transport action. Remove it from both sources,
		// otherwise a POST action could override the BASE3 action from GET.
		foreach (['get', 'post'] as $source) {
			if (($this->{$source}['action'] ?? null) === 'base3_dispatch') {
				unset($this->{$source}['action']);
			}
			if (array_key_exists('base3_action', $this->{$source})) {
				$this->{$source}['action'] = $this->{$source}['base3_action'];
				unset($this->{$source}['base3_action']);
			}
		}
	}

	public static function fromGlobals(): self {
		$request = new self();
		$request->initFromGlobals();
		return $request;
	}
}
