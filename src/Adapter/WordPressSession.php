<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Session\Api\ISession;

/** BASE3-owned PHP session, distinct from WordPress cookie authentication. */
final class WordPressSession implements ISession {

	private const NAMESPACE_KEY = '_base3';

	public function started(): bool { return session_status() === PHP_SESSION_ACTIVE; }
	public function getId(): string { return $this->started() ? session_id() : ''; }

	public function start(): bool {
		if ($this->started()) return true;
		if (headers_sent()) throw new \RuntimeException('Cannot start BASE3 session after response headers.');
		if (session_status() === PHP_SESSION_DISABLED) throw new \RuntimeException('PHP session support is disabled.');
		session_set_cookie_params(['httponly' => true, 'secure' => is_ssl(), 'samesite' => 'Lax', 'path' => defined('COOKIEPATH') ? COOKIEPATH : '/']);
		return session_start();
	}

	public function destroy(): bool {
		if (!$this->started()) return false;
		unset($_SESSION[self::NAMESPACE_KEY]);
		return session_destroy();
	}

	public function get(string $key, mixed $default = null): mixed {
		$this->start();
		return $_SESSION[self::NAMESPACE_KEY][$key] ?? $default;
	}

	public function set(string $key, mixed $value): void {
		$this->start();
		$_SESSION[self::NAMESPACE_KEY][$key] = $value;
	}

	public function has(string $key): bool {
		$this->start();
		return isset($_SESSION[self::NAMESPACE_KEY]) && array_key_exists($key, $_SESSION[self::NAMESPACE_KEY]);
	}

	public function remove(string $key): void {
		$this->start();
		unset($_SESSION[self::NAMESPACE_KEY][$key]);
	}
}
