<?php declare(strict_types=1);

namespace Base3WordPress\Api;

/** Project-owned catalog of BASE3 tools displayed in the WordPress administration. */
interface IBase3WordPressSettings {

	/**
	 * Builds the current user's effective navigation without granting endpoint access.
	 * @return list<array<string,mixed>> Filtered tabs and their actual display definitions
	 */
	public function getAdministrationConfig(): array;

	/**
	 * Classifies a routable BASE3 display based on the unfiltered project catalog.
	 * Admin-visible views require a native WordPress manage_options capability; development
	 * views additionally require effective BASE3 development membership. Public displays
	 * (e.g. games) and outputs not in the administration catalog return null, and must
	 * enforce their own authorizations. The result is not itself a native permission grant.
	 * @param string $name Routable IDisplay::getName(), never a tab label
	 * @return string|null 'admin', 'development', or null for non-administration displays
	 */
	public function getAdministrationAccessLevel(string $name): ?string;
}
