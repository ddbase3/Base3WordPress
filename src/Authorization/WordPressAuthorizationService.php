<?php declare(strict_types=1);

namespace Base3WordPress\Authorization;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3WordPress\Service\WordPressFeatureConfiguration;
use InvalidArgumentException;
use Throwable;

/** Single-site WordPress authority: native capabilities and discoverable BASE3 feature audiences. */
final class WordPressAuthorizationService implements IAuthorizationService {

	public const AUTHORITY = 'wordpress';

	public function __construct(
		private readonly IUsermanager $usermanager,
		private readonly WordPressFeatureConfiguration $features,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	public function getDefaultAuthority(): string { return self::AUTHORITY; }

	/** Evaluates only the trusted active WordPress actor; explicit delegation is unsupported. */
	public function decide(AuthorizationRequest $request): AuthorizationDecision {
		if ($request->authority !== self::AUTHORITY) return AuthorizationDecision::indeterminate('unknown_authority');
		if ($request->subject !== null || $request->onBehalfOf !== null) return AuthorizationDecision::deny('unsupported_principal');
		if ($request->parameters !== [] || $request->context !== []) return AuthorizationDecision::deny('unsupported_action_context');
		$scope = (string)$request->permission->scope;
		$action = (string)$request->permission->permission;
		try {
			if ($scope === 'system' && $action === 'admin' && $request->resource === null) {
				return current_user_can('manage_options') ? AuthorizationDecision::allow('wordpress_manage_options') : AuthorizationDecision::deny('wordpress_capability_denied');
			}
			if ($scope === 'wordpress') return $this->native($request, $action);
			if ($scope === 'feature' && $request->resource === null) {
				$feature = $this->features->forPermission($scope, $action);
				if ($feature === null) return AuthorizationDecision::deny('unknown_feature');
				$groups = array_map(static fn($group): string => (string)$group->name, $this->usermanager->getGroups());
				$audience = $this->features->getAudience($feature);
				return $this->evaluator->matches($this->usermanager->getUser(), $audience, $groups)
					? AuthorizationDecision::allow('base3_feature_audience') : AuthorizationDecision::deny('feature_audience_denied');
			}
			return AuthorizationDecision::deny('unsupported_permission');
		} catch (Throwable) {
			return AuthorizationDecision::indeterminate('authority_evaluation_failed');
		}
	}

	/** @return array<int|string,AuthorizationDecision> Ordered WordPress/native-feature decisions. */
	public function decideMany(array $requests): array {
		$results = [];
		foreach ($requests as $key => $request) {
			if (!$request instanceof AuthorizationRequest) throw new InvalidArgumentException('Expected AuthorizationRequest.');
			$results[$key] = $this->decide($request);
		}
		return $results;
	}

	/** Uses WordPress metadata-capability resolution for exact supported resource kinds. */
	private function native(AuthorizationRequest $request, string $capability): AuthorizationDecision {
		if ($capability === '') return AuthorizationDecision::deny('invalid_wordpress_capability');
		$resource = $request->resource;
		if ($resource === null) {
			return current_user_can($capability) ? AuthorizationDecision::allow('wordpress_capability') : AuthorizationDecision::deny('wordpress_capability_denied');
		}
		$allowed = match ($resource->type) {
			'post' => ['read_post', 'edit_post', 'delete_post'],
			'user' => ['edit_user', 'delete_user', 'remove_user', 'promote_user'],
			'term' => ['edit_term', 'delete_term', 'assign_term'],
			'comment' => ['edit_comment'],
			default => []
		};
		if (!in_array($capability, $allowed, true) || filter_var($resource->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
			return AuthorizationDecision::deny('unsupported_native_resource');
		}
		return current_user_can($capability, (int)$resource->id)
			? AuthorizationDecision::allow('wordpress_meta_capability') : AuthorizationDecision::deny('wordpress_capability_denied');
	}
}
