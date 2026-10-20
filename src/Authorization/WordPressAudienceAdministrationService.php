<?php declare(strict_types=1);

namespace Base3WordPress\Authorization;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3WordPress\Service\WordPressAudienceEditor;
use Base3WordPress\Service\WordPressFeatureConfiguration;
use Base3WordPress\Service\WordPressGroupConfiguration;
use InvalidArgumentException;
use RuntimeException;
use UiFoundation\Api\IAuthorizationAudienceAdministrationService;

/** WordPress host adapter: reuse ClientStack's Groups and Features displays without a second editor. */
final class WordPressAudienceAdministrationService implements IAuthorizationAudienceAdministrationService {

	public function __construct(
		private readonly IAuthorizationService $authorization,
		private readonly IUsermanager $usermanager,
		private readonly WordPressGroupConfiguration $groups,
		private readonly WordPressFeatureConfiguration $features,
		private readonly WordPressAudienceEditor $editor
	) {}

	/**
	 * Checks both native site administration and the explicitly configured BASE3 developer group.
	 * @return void No permissions are changed
	 * @throws RuntimeException On native denial or missing developer membership
	 * @sideeffects None
	 */
	public function assertCanAdminister(): void {
		$decision = $this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')
		));
		if (!$decision->isAllowed()) throw new RuntimeException('WordPress BASE3 administration denied.');
		foreach ($this->usermanager->getGroups() as $group) {
			if ((string)$group->name === 'development') return;
		}
		throw new RuntimeException('WordPress BASE3 developer membership required.');
	}

	/**
	 * Builds the unchanged shared group editor's view from discovered and managed WordPress groups.
	 * @return array{entries:list<array>,defaultGroup:string,criterionOptions:list<array>,roleOptions:list<array>,roleHint:string}
	 * @throws RuntimeException On unauthorized access
	 * @sideeffects None
	 */
	public function getGroupView(): array {
		$this->assertCanAdminister();
		$entries = [];
		foreach ($this->groups->getEntries() as $entry) {
			$entries[] = [
				'name' => (string)$entry['group']->name,
				'description' => (string)$entry['group']->info,
				'editable' => $entry['editable'],
				'source' => $entry['source'],
				'rows' => $this->editor->describe($entry['configuredCriteria']),
				'codeCriteria' => $entry['codeCriteria'],
				'expression' => $entry['criteria']
			];
		}
		return [
			'entries' => $entries,
			'defaultGroup' => $this->groups->getDefaultGroup(),
			'criterionOptions' => $this->criterionOptions(),
			'roleOptions' => [],
			'roleHint' => 'WordPress role slugs are configured through wordpressrolecriterion.'
		];
	}

	/**
	 * Validates the shared UI's posted assignments and atomically persists membership/fallback settings.
	 * @param array<string,array> $rows Untrusted rows keyed by existing editable BASE3 group
	 * @param string $defaultGroup Optional nonprivileged fallback for authenticated WordPress users
	 * @return void
	 * @throws InvalidArgumentException On invalid rows, protected groups or privileged fallback
	 * @throws RuntimeException When unauthorized
	 * @sideeffects Updates only WordPress-owned BASE3 group settings
	 */
	public function replaceGroupRules(array $rows, string $defaultGroup): void {
		$this->assertCanAdminister();
		$editable = [];
		foreach ($this->groups->getEntries() as $entry) {
			if ($entry['editable']) $editable[(string)$entry['group']->name] = true;
		}
		foreach ($rows as $name => $items) {
			if (!is_string($name) || !isset($editable[$name]) || !is_array($items)) {
				throw new InvalidArgumentException('Unknown or protected BASE3 group.');
			}
		}
		$rules = [];
		foreach ($editable as $name => $_) $rules[$name] = $this->editor->parse($rows[$name] ?? [], null);
		$this->groups->replaceAll($rules, $defaultGroup);
	}

	/**
	 * Creates a WordPress-managed BASE3 group DTO, not a WordPress role.
	 * @param string $name Stable technical group name
	 * @param string $description Human-readable nonempty label
	 * @return void
	 * @throws InvalidArgumentException On malformed or duplicate definitions
	 * @throws RuntimeException When unauthorized
	 * @sideeffects Persists one host-managed BASE3 group
	 */
	public function createGroup(string $name, string $description): void {
		$this->assertCanAdminister();
		$this->groups->create($name, $description);
	}

	/**
	 * Removes only an unreferenced host-managed group, never a code-owned or fallback group.
	 * @param string $name Existing managed group name
	 * @return void
	 * @throws InvalidArgumentException On unknown, protected or referenced group
	 * @throws RuntimeException When unauthorized
	 * @sideeffects Removes one host-managed BASE3 group
	 */
	public function removeGroup(string $name): void {
		$this->assertCanAdminister();
		$this->groups->remove($name);
	}

	/**
	 * Builds the shared feature editor from WordPress's discovered feature catalog.
	 * @return array{rows:list<array>,groups:list<string>,criterionOptions:list<array>,roleOptions:list<array>,roleHint:string}
	 * @throws RuntimeException When unauthorized
	 * @sideeffects None
	 */
	public function getFeatureView(): array {
		$this->assertCanAdminister();
		$rows = [];
		foreach ($this->features->getCatalog() as $feature) {
			$permissions = [];
			foreach ($feature->getPermissions() as $permission) {
				$permissions[] = (string)$permission->scope . '/' . (string)$permission->permission;
			}
			$rows[] = [
				'name' => $feature::getName(),
				'label' => $feature->getLabel(),
				'description' => $feature->getDescription(),
				'permissions' => $permissions,
				'rules' => $this->editor->describe($this->features->getAudience($feature))
			];
		}
		return [
			'rows' => $rows,
			'groups' => array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups()),
			'criterionOptions' => $this->criterionOptions(),
			'roleOptions' => [],
			'roleHint' => 'WordPress role slugs are configured through wordpressrolecriterion.'
		];
	}

	/**
	 * Validates and replaces audiences for every discovered feature via the existing settings owner.
	 * @param array<string,array> $rows Untrusted assignment rows keyed by discovered feature name
	 * @return void
	 * @throws InvalidArgumentException On invalid feature names, rules or group references
	 * @throws RuntimeException When unauthorized
	 * @sideeffects Replaces host-managed feature audience overrides only
	 */
	public function replaceFeatureRules(array $rows): void {
		$this->assertCanAdminister();
		$catalog = $this->features->getCatalog();
		foreach ($rows as $name => $items) {
			if (!is_string($name) || !isset($catalog[$name]) || !is_array($items)) {
				throw new InvalidArgumentException('Unknown WordPress feature.');
			}
		}
		$groups = array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups());
		$rules = [];
		foreach ($catalog as $name => $_) $rules[$name] = $this->editor->parse($rows[$name] ?? [], $groups);
		$this->features->replaceAll($rules, $groups);
	}

	/**
	 * Returns schema-backed selector options accepted by the existing UserCriterionEvaluator.
	 * @return list<array{name:string,schema:array}>
	 * @sideeffects None
	 */
	private function criterionOptions(): array {
		$items = [];
		foreach ($this->editor->getCriteria() as $name => $schema) $items[] = ['name' => $name, 'schema' => $schema];
		return $items;
	}
}
