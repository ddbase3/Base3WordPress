<?php declare(strict_types=1);

namespace Base3WordPress\Display;

use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3WordPress\Service\WordPressAudienceEditor;
use Base3WordPress\Service\WordPressFeatureConfiguration;
use Base3WordPress\Service\WordPressGroupConfiguration;
use InvalidArgumentException;

/** Shared WordPress-hosted admin UI for BASE3 logical groups and discoverable feature audiences. */
final class Base3WordPressAudienceAdminDisplay implements IDisplay {

	private string $mode = '';

	public function __construct(
		private readonly IAuthorizationService $authorization,
		private readonly IUsermanager $usermanager,
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly ILinkTargetService $links,
		private readonly WordPressGroupConfiguration $groups,
		private readonly WordPressFeatureConfiguration $features,
		private readonly WordPressAudienceEditor $editor
	) {}

	public static function getName(): string { return 'base3wordpressaudienceadmindisplay'; }

	/** @param mixed $data Tab-specific editor mode; controls no authorization decision. */
	public function setData($data) {
		if (is_array($data) && is_string($data['mode'] ?? null)) $this->mode = $data['mode'];
	}

	/** @return string Host-rendered HTML form; denies direct AJAX and embedded access without native admin AND BASE3 development. */
	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') throw new InvalidArgumentException('The audience editor renders HTML only.');
		if (!$this->authorized()) {
			http_response_code(403);
			return '<p role="alert">BASE3 developer administration access denied.</p>';
		}
		$mode = (string)$this->request->get('base3_mode', $this->mode);
		if (!in_array($mode, ['groups','features'], true)) {
			http_response_code(400);
			return '<p role="alert">Unknown BASE3 audience editor.</p>';
		}
		$message = '';
		$error = '';
		$action = $this->request->post('base3_audience_action', '');
		if ($action !== '') {
			if (!is_string($action) || !wp_verify_nonce((string)$this->request->post('base3_audience_nonce', ''), 'base3_audience_admin')) {
				http_response_code(403);
				return '<p role="alert">Invalid WordPress administration nonce.</p>';
			}
			try {
				$submitted = $this->request->post('base3_rules', []);
				if ($action === 'save' && is_array($submitted) && $mode === 'groups') {
					$this->saveGroups($submitted);
					$message = 'Group memberships saved.';
				} elseif ($action === 'save' && is_array($submitted) && $mode === 'features') {
					$this->saveFeatures($submitted);
					$message = 'Feature audiences saved.';
				} elseif ($mode === 'groups' && $action === 'create') {
					$this->groups->create((string)$this->request->post('base3_group_name', ''), (string)$this->request->post('base3_group_description', ''));
					$message = 'BASE3 group created.';
				} elseif ($mode === 'groups' && $action === 'remove') {
					$this->groups->remove((string)$this->request->post('base3_group_name', ''));
					$message = 'Custom BASE3 group removed.';
				} else {
					throw new InvalidArgumentException('Unknown audience operation.');
				}
			} catch (InvalidArgumentException $exception) {
				http_response_code(400);
				$error = 'The audience configuration was rejected: ' . $exception->getMessage();
			}
		}
		$groups = array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups());
		$entries = [];
		if ($mode === 'groups') {
			foreach ($this->groups->getEntries() as $entry) {
				$entries[] = [
					'name' => (string)$entry['group']->name,
					'label' => (string)$entry['group']->name,
					'description' => (string)$entry['group']->info,
					'editable' => $entry['editable'],
					'source' => $entry['source'],
					'fixed' => $entry['codeCriteria'],
					'rows' => $this->editor->describe($entry['configuredCriteria'])
				];
			}
		} else {
			foreach ($this->features->getCatalog() as $feature) {
				$entries[] = [
					'name' => $feature::getName(), 'label' => $feature->getLabel(),
					'description' => $feature->getDescription(), 'editable' => true,
					'source' => 'feature', 'fixed' => [],
					'rows' => $this->editor->describe($this->features->getAudience($feature))
				];
			}
		}
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->setTemplate('Display/Base3WordPressAudienceAdminDisplay.php');
		foreach ([
			'mode' => $mode, 'entries' => $entries, 'groupNames' => $groups,
			'roles' => $this->editor->getRoles(), 'criteria' => $this->editor->getCriteria(),
			'message' => $message, 'error' => $error,
			'nonce' => wp_create_nonce('base3_audience_admin'),
			'endpoint' => $this->links->getLink(['name'=>self::getName(), 'out'=>'html'], ['base3_mode'=>$mode])
		] as $key => $value) $this->view->assign($key, $value);
		return $this->view->loadTemplate();
	}

	private function authorized(): bool {
		if (!$this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(), Permission::for('system','admin')
		))->isAllowed()) return false;
		foreach ($this->usermanager->getGroups() as $group) {
			if ((string)$group->name === 'development') return true;
		}
		return false;
	}

	/** @param array<string,mixed> $submitted Submitted per-group rows. */
	private function saveGroups(array $submitted): void {
		$entries = [];
		foreach ($this->groups->getEntries() as $entry) {
			if ($entry['editable']) $entries[(string)$entry['group']->name] = true;
		}
		foreach ($submitted as $name => $rows) {
			if (!isset($entries[$name]) || !is_array($rows)) throw new InvalidArgumentException('Unknown or locked group in form.');
		}
		$rules = [];
		foreach ($entries as $name => $_) $rules[$name] = $this->editor->parse($submitted[$name] ?? [], null);
		$this->groups->replaceAll($rules);
	}

	/** @param array<string,mixed> $submitted Submitted per-feature rows. */
	private function saveFeatures(array $submitted): void {
		$catalog = $this->features->getCatalog();
		foreach ($submitted as $name => $rows) {
			if (!isset($catalog[$name]) || !is_array($rows)) throw new InvalidArgumentException('Unknown feature in form.');
		}
		$groups = array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups());
		$rules = [];
		foreach ($catalog as $name => $_) $rules[$name] = $this->editor->parse($submitted[$name] ?? [], $groups);
		$this->features->replaceAll($rules, $groups);
	}
}
