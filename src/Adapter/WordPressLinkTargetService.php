<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\LinkTarget\Api\ILinkTargetService;

final class WordPressLinkTargetService implements ILinkTargetService {

	public function getLink(array $target, array $params = []): string {
		$named = [
			'action' => 'base3_dispatch',
			'name' => (string) ($target['name'] ?? ''),
			'out' => (string) ($target['out'] ?? $params['out'] ?? 'php')
		];
		// action belongs to WordPress for routing. BASE3 receives the original
		// argument via WordPressRequest after WordPress has dispatched the call.
		if (array_key_exists('action', $params)) {
			$params['base3_action'] = $params['action'];
			unset($params['action']);
		}
		foreach (['name', 'out', '_wpnonce'] as $reserved) {
			unset($params[$reserved]);
		}
		if (current_user_can('manage_options')) {
			$named['_wpnonce'] = wp_create_nonce('base3_admin_display');
		}
		return add_query_arg(array_merge($named, $params), admin_url('admin-ajax.php'));
	}
}
