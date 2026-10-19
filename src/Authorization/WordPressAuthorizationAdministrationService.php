<?php declare(strict_types=1);

namespace Base3WordPress\Authorization;

use Base3\Authorization\Api\IAuthorizationAdministrationService;
use Base3\Authorization\Dto\AuthorizationCapabilities;
use Base3\Authorization\Dto\AuthorizationGrant;
use Base3\Authorization\Dto\AuthorizationSubject;
use Base3\Authorization\Dto\ResourceReference;
use Base3\Authorization\Dto\SubjectReference;
use Base3\Usermanager\Permission;
use InvalidArgumentException;
use RuntimeException;

/** Read-only native WordPress role/capability inspection; no fake per-resource grant editor. */
final class WordPressAuthorizationAdministrationService implements IAuthorizationAdministrationService {

	/** @return AuthorizationCapabilities Explicit native capability limits for this installation. */
	public function describe(string $authority, ?ResourceReference $resource = null): AuthorizationCapabilities {
		$this->assertAuthority($authority);
		if ($resource !== null) $this->assertSite($resource);
		$canRead = current_user_can('manage_options');
		return new AuthorizationCapabilities($authority, $canRead, false, false, ['role'], [
			'WordPress capabilities are native role settings; granular per-object grants are not exposed by WordPress core.',
			'Editing native role capabilities via BASE3 is unavailable until atomic host grant replacement is supported.'
		]);
	}

	/** @return list<Permission> Global capability definitions from the native WordPress role catalog. */
	public function getPermissions(string $authority, ?ResourceReference $resource = null): array {
		$this->assertAuthority($authority);
		if ($resource !== null) $this->assertSite($resource);
		$this->requireAdmin();
		$caps = [];
		foreach (wp_roles()->roles as $role) {
			foreach ($role['capabilities'] ?? [] as $key => $_) $caps[(string)$key] = Permission::for('wordpress', (string)$key);
		}
		ksort($caps);
		return array_values($caps);
	}

	/** @return list<AuthorizationSubject> Native WordPress roles, marked read-only. */
	public function getSubjects(ResourceReference $resource): array {
		$this->assertSite($resource);
		$this->requireAdmin();
		$subjects = [];
		foreach (wp_roles()->roles as $slug => $definition) {
			$subjects[] = new AuthorizationSubject(new SubjectReference($resource->authority, 'role', (string)$slug), (string)($definition['name'] ?? $slug), false);
		}
		return $subjects;
	}

	/** @return list<AuthorizationGrant> Native role/capability grants with no fabricated inheritance. */
	public function getGrants(ResourceReference $resource): array {
		$this->assertSite($resource);
		$this->requireAdmin();
		$grants = [];
		foreach (wp_roles()->roles as $slug => $definition) {
			$subject = new SubjectReference($resource->authority, 'role', (string)$slug);
			foreach ($definition['capabilities'] ?? [] as $capability => $enabled) {
				if ($enabled) $grants[] = new AuthorizationGrant($subject, Permission::for('wordpress', (string)$capability), $resource, false, null);
			}
		}
		return $grants;
	}

	/**
	 * Rejects write attempts: WordPress does not provide atomic, resource-scoped role grant replacement.
	 * @param ResourceReference $resource Native WordPress global role catalog
	 * @param SubjectReference $subject Role subject
	 * @param list<Permission> $permissions Requested replacement capabilities
	 * @throws RuntimeException Always; this authority reports read-only grants
	 */
	public function replaceGrants(ResourceReference $resource, SubjectReference $subject, array $permissions): void {
		$this->assertSite($resource);
		throw new RuntimeException('Native WordPress capability grants are read-only in BASE3.');
	}

	private function assertAuthority(string $authority): void {
		if ($authority !== WordPressAuthorizationService::AUTHORITY) throw new InvalidArgumentException('Wrong WordPress authority.');
	}

	private function assertSite(ResourceReference $resource): void {
		$this->assertAuthority($resource->authority);
		if ($resource->type !== 'site' || (string)$resource->id !== 'global') throw new InvalidArgumentException('Only global WordPress role capabilities can be inspected.');
	}

	private function requireAdmin(): void {
		if (!current_user_can('manage_options')) throw new RuntimeException('WordPress administrator capability required.');
	}
}
