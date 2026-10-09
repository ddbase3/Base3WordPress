<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Configuration\AbstractConfiguration;

/** Editable BASE3 configuration stored in WordPress options; host directories remain authoritative. */
final class WordPressConfiguration extends AbstractConfiguration {

	protected function load(): array {
		$value = get_option('base3_configuration', []);
		$data = is_array($value) ? $value : [];
		$data['directories'] = array_replace(is_array($data['directories'] ?? null) ? $data['directories'] : [], ['data' => DIR_LOCAL]);
		$data['base'] = array_replace(['endpoint' => admin_url('admin-ajax.php?action=base3_dispatch')], is_array($data['base'] ?? null) ? $data['base'] : []);
		return $data;
	}

	protected function saveData(array $data): bool {
		// Physical directories and host-managed credentials are not user-editable BASE3 settings.
		unset($data['directories']);
		return update_option('base3_configuration', $data, false) || get_option('base3_configuration') === $data;
	}
}
