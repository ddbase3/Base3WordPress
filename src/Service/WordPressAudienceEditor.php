<?php declare(strict_types=1);

namespace Base3WordPress\Service;

use Base3\Api\IClassMap;
use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\UserCriterionEvaluator;
use InvalidArgumentException;

/** Shared, lossless form adapter for the existing BASE3 criterion-expression grammar. */
final class WordPressAudienceEditor {

	public function __construct(
		private readonly IClassMap $classMap,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	/** @return array<string,array<string,mixed>> Criteria and exact advertised input schemas by getName(). */
	public function getCriteria(): array {
		$all = [];
		foreach ($this->classMap->getInstancesByInterface(IUserCriterion::class) as $criterion) {
			if (!$criterion instanceof IUserCriterion) continue;
			$name = $criterion::getName();
			if (isset($all[$name])) throw new InvalidArgumentException('Duplicate BASE3 criterion: ' . $name);
			$all[$name] = $criterion->getSchema();
		}
		ksort($all);
		return $all;
	}

	/** @return array<string,string> Currently registered WordPress role slugs and labels. */
	public function getRoles(): array {
		$roles = [];
		foreach (wp_roles()->role_names as $slug => $label) $roles[(string)$slug] = translate_user_role((string)$label);
		ksort($roles);
		return $roles;
	}

	/**
	 * Converts repeatable OR assignment rows to one audience expression; no parallel policy evaluator.
	 * @param array<mixed> $rows Submitted rows of type user, role, group, criterion or expression
	 * @param list<string>|null $groups Known groups when feature editing is allowed, null for group memberships
	 * @return array<string,mixed> Validated OR expression or [] for denial
	 * @throws InvalidArgumentException On invalid role, JSON, unknown criterion, group or depth
	 */
	public function parse(array $rows, ?array $groups): array {
		if (count($rows) > 100) throw new InvalidArgumentException('Too many audience rules.');
		$items = [];
		foreach ($rows as $row) {
			if (!is_array($row)) throw new InvalidArgumentException('Invalid rule row.');
			$kind = $row['kind'] ?? null;
			$value = $row['value'] ?? null;
			$config = $row['config'] ?? '{}';
			switch ($kind) {
				case 'user':
					$id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
					if ($id === false || get_user_by('id', $id) === false) throw new InvalidArgumentException('Unknown WordPress user ID.');
					$items[] = ['criterion'=>'base3useridcriterion', 'config'=>['user_ids'=>[$id]]];
					break;
				case 'role':
					if (!is_string($value) || !array_key_exists($value, $this->getRoles())) throw new InvalidArgumentException('Unknown WordPress role.');
					$items[] = ['criterion'=>'wordpressrolecriterion', 'config'=>['roles'=>[$value]]];
					break;
				case 'group':
					if ($groups === null || !is_string($value) || !in_array($value, $groups, true)) throw new InvalidArgumentException('Unknown BASE3 group.');
					$items[] = ['group'=>$value];
					break;
				case 'criterion':
					if (!is_string($value) || !isset($this->getCriteria()[$value])) throw new InvalidArgumentException('Unknown criterion.');
					$items[] = ['criterion'=>$value, 'config'=>$this->json($config)];
					break;
				case 'expression':
					$items[] = $this->json($config);
					break;
				default:
					throw new InvalidArgumentException('Unknown audience selector.');
			}
		}
		$result = $items === [] ? [] : ['any'=>$items];
		$this->evaluator->validate($result, $groups);
		return $result;
	}

	/**
	 * Describes simple additions as rows while preserving complex expressions as JSON exactly.
	 * @param array<string,mixed> $expression Existing validated expression
	 * @return list<array{kind:string,value:string,config:string}> Form rows
	 */
	public function describe(array $expression): array {
		if ($expression === []) return [];
		$items = array_keys($expression) === ['any'] && is_array($expression['any']) ? $expression['any'] : [$expression];
		$rows = [];
		foreach ($items as $item) {
			if (!is_array($item)) throw new InvalidArgumentException('Invalid audience expression.');
			if (array_keys($item) === ['group'] && is_string($item['group'])) {
				$rows[] = ['kind'=>'group', 'value'=>$item['group'], 'config'=>'{}'];
				continue;
			}
			if (isset($item['criterion']) && is_string($item['criterion'])) {
				$name = $item['criterion'];
				$config = $item['config'] ?? [];
				if ($name === 'base3useridcriterion' && count($config['user_ids'] ?? []) === 1) {
					$rows[] = ['kind'=>'user', 'value'=>(string)$config['user_ids'][0], 'config'=>'{}'];
					continue;
				}
				$rows[] = ['kind'=>'criterion', 'value'=>$name, 'config'=>json_encode($config === [] ? (object)[] : $config, JSON_THROW_ON_ERROR)];
				continue;
			}
			$rows[] = ['kind'=>'expression', 'value'=>'', 'config'=>json_encode($item, JSON_THROW_ON_ERROR)];
		}
		return $rows;
	}

	/** @return array<string,mixed> Parsed JSON object, never scalar or non-empty list. */
	private function json(mixed $config): array {
		if (!is_string($config) || strlen($config) > 20000) throw new InvalidArgumentException('Audience JSON must be a short object.');
		try { $data = json_decode($config, true, 20, JSON_THROW_ON_ERROR); }
		catch (\JsonException $error) { throw new InvalidArgumentException('Invalid audience JSON.', 0, $error); }
		if (!is_array($data) || (array_is_list($data) && $data !== [])) throw new InvalidArgumentException('Audience JSON must describe an object.');
		return $data;
	}
}
