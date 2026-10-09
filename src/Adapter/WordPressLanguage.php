<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Language\Api\ILanguage;

final class WordPressLanguage implements ILanguage {

	private ?string $language = null;

	public function getLanguage(): string {
		if ($this->language !== null) return $this->language;
		$locale = function_exists('determine_locale') ? determine_locale() : get_locale();
		return strtolower(explode('_', str_replace('-', '_', $locale))[0]);
	}

	public function setLanguage(string $language) { $this->language = strtolower(trim($language)); }

	public function getLanguages(): array {
		$locale = $this->getLanguage();
		return array_values(array_unique([$locale, 'de', 'en']));
	}
}
