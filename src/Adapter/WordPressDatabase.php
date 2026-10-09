<?php declare(strict_types=1);

namespace Base3WordPress\Adapter;

use Base3\Database\Api\IDatabase;
use RuntimeException;

/** Independent SQL connection to the *existing* WordPress MariaDB database.
 * WordPress's $wpdb connection is not closed or transaction-managed by BASE3.
 */
final class WordPressDatabase implements IDatabase {

	private ?\mysqli $connection = null;
	private int $affected = 0;
	private int|string $insertId = 0;
	private int $lastCode = 0;
	private string $lastMessage = '';

	public function connect(): void {
		if ($this->connected()) return;
		if (!extension_loaded('mysqli')) throw new RuntimeException('The mysqli PHP extension is required for BASE3 WordPress database access.');
		$hostSpec = (string) DB_HOST;
		$host = $hostSpec;
		$port = 3306;
		$socket = null;
		if (preg_match('/^(.+?):([0-9]+)$/', $hostSpec, $matches)) {
			$host = $matches[1]; $port = (int) $matches[2];
		} elseif (preg_match('/^(.+?):(\/.*)$/', $hostSpec, $matches)) {
			$host = $matches[1]; $socket = $matches[2];
		}
		try {
			$this->connection = new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket);
			if (!$this->connection->set_charset('utf8mb4')) throw new RuntimeException('Failed to set BASE3 database charset.');
			$this->clearError();
		} catch (\Throwable $error) {
			$this->connection = null;
			$this->rememberError($error);
			throw $error;
		}
	}

	public function connected(): bool { return $this->connection !== null; }
	public function disconnect(): void {
		if ($this->connection !== null) $this->connection->close();
		$this->connection = null;
	}

	public function beginTransaction(): void {
		$this->runOperation(static fn(\mysqli $db) => $db->begin_transaction(), 'begin transaction');
	}

	public function commit(): void {
		$this->runOperation(static fn(\mysqli $db) => $db->commit(), 'commit');
	}

	public function rollback(): void {
		$this->runOperation(static fn(\mysqli $db) => $db->rollback(), 'rollback');
	}

	public function nonQuery(string $query): void {
		$this->exec($query);
		$this->affected = $this->connection->affected_rows;
		$this->insertId = $this->connection->insert_id;
	}

	public function scalarQuery(string $query): mixed {
		$rows = $this->multiQuery($query);
		return $rows === [] ? null : (array_values($rows[0])[0] ?? null);
	}

	public function singleQuery(string $query): ?array {
		$rows = $this->multiQuery($query);
		return $rows[0] ?? null;
	}

	public function &listQuery(string $query): array {
		$rows = $this->multiQuery($query);
		$list = array_map(static fn(array $row) => array_values($row)[0] ?? null, $rows);
		return $list;
	}

	public function &multiQuery(string $query): array {
		$result = $this->exec($query);
		if (!$result instanceof \mysqli_result) throw new RuntimeException('BASE3 query returned no result set.');
		try {
			$rows = $result->fetch_all(MYSQLI_ASSOC);
		} finally {
			$result->free();
		}
		return $rows;
	}

	public function affectedRows(): int { return $this->affected; }
	public function insertId(): int|string { return $this->insertId; }
	public function escape(string $str): string {
		$this->connect();
		return $this->connection->real_escape_string($str);
	}
	public function isError(): bool { return $this->lastMessage !== ''; }
	public function errorNumber(): int { return $this->lastCode; }
	public function errorMessage(): string { return $this->lastMessage; }

	private function exec(string $query): \mysqli_result|bool {
		$this->connect();
		$this->clearError();
		$this->affected = 0;
		$this->insertId = 0;
		try {
			$result = $this->connection->query($query);
			if ($result === false) throw new RuntimeException($this->connection->error);
			return $result;
		} catch (\Throwable $error) {
			$this->rememberError($error);
			throw $error;
		}
	}

	private function runOperation(callable $callback, string $name): void {
		$this->connect();
		$this->clearError();
		try {
			if (!$callback($this->connection)) throw new RuntimeException('BASE3 database ' . $name . ' failed: ' . $this->connection->error);
		} catch (\Throwable $error) {
			$this->rememberError($error);
			throw $error;
		}
	}

	private function clearError(): void { $this->lastCode = 0; $this->lastMessage = ''; }
	private function rememberError(\Throwable $error): void {
		$this->lastCode = $this->connection?->errno ?? (int) $error->getCode();
		$this->lastMessage = $error->getMessage();
	}
}
