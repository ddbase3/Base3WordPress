<?php declare(strict_types=1);

namespace Base3WordPress\Service;

use Base3\Api\IClassMap;
use Base3\Authorization\Api\IFeature;
use Base3\Settings\Api\ISettingsStore;
use Base3\Usermanager\UserCriterionEvaluator;
use InvalidArgumentException;

/** WordPress-hosted overrides of discoverable BASE3 feature audiences. */
final class WordPressFeatureConfiguration {

	private const GROUP = 'base3_authorization_features';
	private const NAME = 'catalog';
	private ?array $overrides = null;

	public function __construct(
		private readonly ISettingsStore $settings,
		private readonly IClassMap $classMap,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	/** @return array<string,IFeature> Discoverable features keyed by their unique technical name. */
	public function getCatalog(): array {
		$features = [];
		$permissions = [];
		foreach ($this->classMap->getInstancesByInterface(IFeature::class) as $feature) {
			if (!$feature instanceof IFeature) continue;
			$name = $feature::getName();
			if (isset($features[$name])) throw new InvalidArgumentException('Duplicate BASE3 feature name: ' . $name);
			foreach ($feature->getPermissions() as $permission) {
				$key = $permission->scope . '/' . $permission->permission;
				if (isset($permissions[$key])) throw new InvalidArgumentException('Duplicate feature permission: ' . $key);
				$permissions[$key] = true;
			}
			$features[$name] = $feature;
		}
		ksort($features);
		return $features;
	}

	/** @return IFeature|null Feature owning an exact permission, or null if not discoverable. */
	public function forPermission(string $scope, string $operation): ?IFeature {
		foreach ($this->getCatalog() as $feature) {
			foreach ($feature->getPermissions() as $permission) {
				if ($permission->scope === $scope && $permission->permission === $operation) return $feature;
			}
		}
		return null;
	}

	/** @return array<string,mixed> Explicit [] denies; an absent override uses the feature default. */
	public function getAudience(IFeature $feature): array {
		$rules = $this->read();
		$name = $feature::getName();
		return array_key_exists($name, $rules) ? $rules[$name] : $feature->getDefaultAudience();
	}

	/**
	 * Replaces a single host-owned settings dataset after validating all audiences.
	 * @param array<string,array<string,mixed>> $rules Audience rules by feature name
	 * @param list<string> $groups Effective BASE3 group catalog names
	 * @throws InvalidArgumentException On unknown features, groups or criterion configurations
	 */
	public function replaceAll(array $rules, array $groups): void {
		$catalog = $this->getCatalog();
		foreach ($rules as $name => $audience) {
			if (!is_string($name) || !isset($catalog[$name]) || !is_array($audience)) throw new InvalidArgumentException('Invalid feature audience.');
			$this->evaluator->validate($audience, $groups);
		}
		$this->settings->set(self::GROUP, self::NAME, ['rules' => $rules]);
		$this->settings->save();
		$this->overrides = $rules;
	}

	/** @return bool Whether an effective feature audience refers to this group. */
	public function referencesGroup(string $name): bool {
		foreach ($this->getCatalog() as $feature) {
			if ($this->containsGroup($this->getAudience($feature), $name)) return true;
		}
		return false;
	}

	/** @return array<string,array<string,mixed>> */
	private function read(): array {
		if ($this->overrides !== null) return $this->overrides;
		$data = $this->settings->get(self::GROUP, self::NAME, []);
		$rules = $data['rules'] ?? [];
		if (!is_array($rules)) throw new InvalidArgumentException('Invalid feature settings.');
		foreach ($rules as $name => $expression) {
			if (!is_string($name) || !is_array($expression)) throw new InvalidArgumentException('Invalid stored feature expression.');
		}
		return $this->overrides = $rules;
	}

	private function containsGroup(array $expression, string $name): bool {
		if (array_key_exists('group', $expression)) return $expression['group'] === $name;
		foreach (['any', 'all'] as $operator) {
			foreach ($expression[$operator] ?? [] as $part) {
				if (is_array($part) && $this->containsGroup($part, $name)) return true;
			}
		}
		return isset($expression['not']) && is_array($expression['not']) && $this->containsGroup($expression['not'], $name);
	}
}
