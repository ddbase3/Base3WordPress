<?php declare(strict_types=1);

namespace Base3WordPress\Criterion;

use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\User;
use InvalidArgumentException;

/** Evaluates a native WordPress global capability through its authoritative permission API. */
final class WordPressCapabilityCriterion implements IUserCriterion {

	public static function getName(): string { return 'wordpresscapabilitycriterion'; }

	/** @return array<string,mixed> JSON schema for a single WordPress capability. */
	public function getSchema(): array {
		return ['type' => 'object', 'required' => ['capability'], 'properties' => [
			'capability' => ['type' => 'string', 'minLength' => 1]
		], 'additionalProperties' => false];
	}

	/**
	 * Evaluates the specified user's native global WordPress capability, without BASE3 grants.
	 * @param User|null $user Trusted native WP user, null for anonymous
	 * @param array<string,mixed> $config The exact native capability name
	 * @return bool Whether WordPress grants that capability
	 * @throws InvalidArgumentException If the configured capability is malformed
	 */
	public function matches(?User $user, array $config): bool {
		$name = $config['capability'] ?? null;
		if (!is_string($name) || $name === '' || count($config) !== 1) throw new InvalidArgumentException('Invalid WordPress capability criterion.');
		return $user !== null && (int)$user->id > 0 && user_can((int)$user->id, $name);
	}
}
