<?php declare(strict_types=1);

namespace Base3WordPress\Criterion;

use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\User;
use InvalidArgumentException;

/** Matches native WordPress role slugs already resolved by the host identity adapter. */
final class WordPressRoleCriterion implements IUserCriterion {

	public static function getName(): string { return 'wordpressrolecriterion'; }

	/** @return array<string,mixed> JSON schema for exact native WordPress role slugs. */
	public function getSchema(): array {
		return ['type' => 'object', 'required' => ['roles'], 'properties' => [
			'roles' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string', 'minLength' => 1]]
		], 'additionalProperties' => false];
	}

	/**
	 * Tests host-supplied native roles, never BASE3-derived group roles (avoids cycles).
	 * @param User|null $user Current trusted identity or null for anonymous
	 * @param array<string,mixed> $config Exact 'roles' list of WordPress role slugs
	 * @return bool True only if a WordPress role matches
	 * @throws InvalidArgumentException On malformed configuration
	 */
	public function matches(?User $user, array $config): bool {
		$names = $config['roles'] ?? null;
		if (!is_array($names) || !array_is_list($names)) throw new InvalidArgumentException('WordPress role list required.');
		if ($user === null) return false;
		foreach ($user->roles as $role) {
			if (in_array((string)$role->name, $names, true)) return true;
		}
		return false;
	}
}
