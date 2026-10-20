<?php declare(strict_types=1);

namespace Base3WordPress\Service;

use Base3\Api\IClassMap;
use Base3\Settings\Api\ISettingsStore;
use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;
use Base3\Usermanager\UserCriterionEvaluator;
use InvalidArgumentException;

/** Canonical BASE3 group catalog: discovered code definitions and WordPress-managed Group DTOs. */
final class WordPressGroupConfiguration {

	private const GROUP = 'base3wordpress';
	private const NAME = 'groups';
	private ?array $data = null;

	public function __construct(
		private readonly ISettingsStore $settings,
		private readonly IClassMap $classMap,
		private readonly UserCriterionEvaluator $evaluator,
		private readonly WordPressFeatureConfiguration $features
	) {}

	/**
	 * @return list<array{group:Group,criteria:array,codeCriteria:array,configuredCriteria:array,editable:bool,source:string}>
	 * @throws InvalidArgumentException If names collide or stored rules are invalid
	 */
	public function getEntries(): array {
		$data = $this->read();
		$entries = [];
		foreach ($this->classMap->getInstancesByInterface(IGroup::class) as $definition) {
			if (!$definition instanceof IGroup) continue;
			$group = $definition->getGroup();
			$name = (string)$group->name;
			if (!$this->validName($name) || isset($entries[$name])) throw new InvalidArgumentException('Duplicate/invalid BASE3 group: ' . $name);
			$fixed = $definition->getCriteria();
			$editable = $definition->isEditable();
			$configured = $editable ? ($data['criteria'][$name] ?? []) : [];
			if (!is_array($fixed) || !is_array($configured)) throw new InvalidArgumentException('Invalid BASE3 group criteria.');
			$rules = $fixed === [] ? $configured : ($configured === [] ? $fixed : ['any' => [$fixed, $configured]]);
			$entries[$name] = [
				'group' => $group, 'criteria' => $rules, 'codeCriteria' => $fixed,
				'configuredCriteria' => $configured, 'editable' => $editable, 'source' => 'code'
			];
		}
		foreach ($data['groups'] ?? [] as $name => $row) {
			if (!is_string($name) || !$this->validName($name) || isset($entries[$name]) || !is_array($row)
				|| !is_string($row['description'] ?? null) || !is_array($row['criteria'] ?? null)) {
				throw new InvalidArgumentException('Invalid managed BASE3 group.');
			}
			$group = Group::fromArray(['id' => $name, 'name' => $name, 'info' => $row['description'], 'roles' => []]);
			$entries[$name] = [
				'group' => $group, 'criteria' => $row['criteria'], 'codeCriteria' => [],
				'configuredCriteria' => $row['criteria'], 'editable' => true, 'source' => 'managed'
			];
		}
		ksort($entries);
		return array_values($entries);
	}

	/** @return list<Group> Code-owned and managed BASE3 logical groups. */
	public function getGroups(): array { return array_column($this->getEntries(), 'group'); }

	/**
	 * Saves editable additions without replacing code-owned membership criteria.
	 * @param array<string,array<string,mixed>> $rules Complete editable group expressions
	 * @throws InvalidArgumentException On locked/unknown groups or invalid criteria
	 */
	public function replaceAll(array $rules, ?string $defaultGroup = null): void {
		$data = $this->read();
		$entries = [];
		foreach ($this->getEntries() as $entry) $entries[(string)$entry['group']->name] = $entry;
		foreach ($rules as $name => $rule) {
			if (!isset($entries[$name]) || !$entries[$name]['editable'] || !is_array($rule)) throw new InvalidArgumentException('Unknown or locked group.');
			$this->evaluator->validate($rule);
		}
		if ($defaultGroup !== null) {
			if ($defaultGroup !== '' && (!isset($entries[$defaultGroup]) || !$entries[$defaultGroup]['editable']
				|| in_array($defaultGroup, ['administration', 'development', 'anonymous', 'authenticated'], true))) {
				throw new InvalidArgumentException('Unknown or privileged fallback group.');
			}
			$data['defaultGroup'] = $defaultGroup;
		}
		foreach ($entries as $name => $entry) {
			if (!$entry['editable']) continue;
			$rule = $rules[$name] ?? [];
			if ($entry['source'] === 'code') $data['criteria'][$name] = $rule;
			else $data['groups'][$name]['criteria'] = $rule;
		}
		$this->persist($data);
	}

	/**
	 * Creates a managed logical BASE3 group without modifying WordPress roles.
	 * @throws InvalidArgumentException On invalid/duplicate names or descriptions
	 */
	public function create(string $name, string $description): void {
		$name = trim($name);
		$description = trim($description);
		if (!$this->validName($name) || $description === '' || mb_strlen($description) > 250) throw new InvalidArgumentException('Invalid group.');
		foreach ($this->getEntries() as $entry) {
			if ((string)$entry['group']->name === $name) throw new InvalidArgumentException('Group exists.');
		}
		$data = $this->read();
		$data['groups'][$name] = ['description' => $description, 'criteria' => []];
		$this->persist($data);
	}

	/**
	 * Deletes an unused managed group; never deletes code-owned groups or referenced names.
	 * @throws InvalidArgumentException On code-owned/unknown/in-use groups
	 */
	public function remove(string $name): void {
		$data = $this->read();
		if (!isset($data['groups'][$name]) || $this->features->referencesGroup($name) || $this->getDefaultGroup() === $name) throw new InvalidArgumentException('Group cannot be removed.');
		unset($data['groups'][$name]);
		$this->persist($data);
	}

	/**
	 * Returns the nonprivileged authenticated-user fallback, or an empty string when disabled.
	 * @return string Name of a current editable, nonprivileged group, or empty string
	 * @throws InvalidArgumentException On a stale, invalid or privileged stored fallback
	 * @sideeffects None
	 */
	public function getDefaultGroup(): string {
		$name = $this->read()['defaultGroup'] ?? '';
		if (!is_string($name)) throw new InvalidArgumentException('Invalid default group.');
		if ($name === '') return '';
		if (in_array($name, ['administration', 'development', 'anonymous', 'authenticated'], true)) {
			throw new InvalidArgumentException('Privileged fallback group.');
		}
		foreach ($this->getEntries() as $entry) {
			if ($entry['editable'] && (string)$entry['group']->name === $name) return $name;
		}
		throw new InvalidArgumentException('Unknown fallback group.');
	}

	/** @return array<string,mixed> Persisted single-site group settings. */
	private function read(): array {
		if ($this->data !== null) return $this->data;
		$data = $this->settings->get(self::GROUP, self::NAME, []);
		if (!is_array($data['criteria'] ?? []) || !is_array($data['groups'] ?? [])) throw new InvalidArgumentException('Invalid BASE3 group dataset.');
		return $this->data = $data;
	}

	private function persist(array $data): void {
		$this->settings->set(self::GROUP, self::NAME, $data);
		$this->settings->save();
		$this->data = $data;
	}

	private function validName(string $name): bool { return (bool)preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $name); }
}
