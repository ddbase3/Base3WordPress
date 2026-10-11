<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Session\Api\ISession;
use RuntimeException;

/** PHP session storage scoped to WordPress's validated login identity. */
final class WordPressSession implements ISession {

	private const NAMESPACE_KEY = '_base3';
	private const AUTH_CONTEXT_KEY = '_base3_auth_context';

	public function started(): bool {
		return session_status() === PHP_SESSION_ACTIVE;
	}

	public function getId(): string {
		if (!$this->started()) return '';
		$this->bindIdentity();
		return session_id();
	}

	public function start(): bool {
		if ($this->started()) {
			$this->bindIdentity();
			return true;
		}
		if (headers_sent()) throw new RuntimeException('Cannot start BASE3 session after response headers.');
		if (session_status() === PHP_SESSION_DISABLED) throw new RuntimeException('PHP session support is disabled.');

		session_set_cookie_params([
			'httponly' => true,
			'secure' => is_ssl(),
			'samesite' => 'Lax',
			'path' => defined('COOKIEPATH') ? COOKIEPATH : '/'
		]);
		if (!session_start()) return false;
		$this->bindIdentity();
		return true;
	}

	public function destroy(): bool {
		if (!$this->started()) return false;
		unset($_SESSION[self::NAMESPACE_KEY], $_SESSION[self::AUTH_CONTEXT_KEY]);
		return session_destroy();
	}

	public function get(string $key, mixed $default = null): mixed {
		$this->start();
		return $_SESSION[self::NAMESPACE_KEY][$key] ?? $default;
	}

	public function set(string $key, mixed $value): void {
		$this->start();
		if (!is_array($_SESSION[self::NAMESPACE_KEY] ?? null)) {
			$_SESSION[self::NAMESPACE_KEY] = [];
		}
		$_SESSION[self::NAMESPACE_KEY][$key] = $value;
	}

	public function has(string $key): bool {
		$this->start();
		return is_array($_SESSION[self::NAMESPACE_KEY] ?? null)
			&& array_key_exists($key, $_SESSION[self::NAMESPACE_KEY]);
	}

	public function remove(string $key): void {
		$this->start();
		unset($_SESSION[self::NAMESPACE_KEY][$key]);
	}

	private function bindIdentity(): void {
		$userId = get_current_user_id();
		$identity = 'anonymous';
		if ($userId > 0) {
			$token = wp_get_session_token();
			if ($token === '') {
				throw new RuntimeException('WordPress login session token is unavailable.');
			}
			$identity = 'wordpress:' . $userId . ':' . hash_hmac('sha256', $token, wp_salt('auth'));
		}

		$previous = $_SESSION[self::AUTH_CONTEXT_KEY] ?? null;
		if ($previous === $identity) return;

		// A PHPSESSID alone must never carry BASE3 state into a different WP login.
		unset($_SESSION[self::NAMESPACE_KEY]);
		if ($previous !== null || $userId > 0) {
			if (headers_sent() || !session_regenerate_id(true)) {
				throw new RuntimeException('Cannot rotate BASE3 session after WordPress identity changed.');
			}
		}
		$_SESSION[self::AUTH_CONTEXT_KEY] = $identity;
	}
}
