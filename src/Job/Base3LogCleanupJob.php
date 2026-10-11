<?php declare(strict_types=1);

namespace Base3WordPress\Job;

use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use Base3\Worker\Api\IPolicyControlledJob;
use Base3\Worker\Policy\PolicyControlledJobTrait;

/** Removes expired BASE3 database log rows in bounded daily batches. */
final class Base3LogCleanupJob implements IPolicyControlledJob {

	use PolicyControlledJobTrait;

	private const CONFIG_GROUP = 'job';
	private const CONFIG_PREFIX = 'base3logcleanupjob.';
	private const DEFAULT_RETENTION_HOURS = 48;
	private const DEFAULT_DELETE_BATCH = 20000;
	private const MAX_DELETE_BATCH = 100000;

	public function __construct(
		private readonly IDatabase $database,
		private readonly IConfiguration $configuration
	) {}

	public static function getName(): string {
		return 'base3logcleanupjob';
	}

	public function isActive() {
		return $this->configuration->getBool(self::CONFIG_GROUP, self::CONFIG_PREFIX . 'active', false);
	}

	public function getPriority() {
		$priority = $this->configuration->getInt(self::CONFIG_GROUP, self::CONFIG_PREFIX . 'priority', 1);
		return max(1, min(10, $priority));
	}

	public function getPolicyDefinition(): array {
		return [
			'policy' => 'dailywindowjobpolicy',
			'data' => [
				'from' => '02:00',
				'to' => '04:00'
			]
		];
	}

	public function go() {
		$this->database->connect();

		if (!$this->logTableExists()) {
			return 'Skip (base3_log does not exist)';
		}

		$retention = $this->configuration->getInt(
			self::CONFIG_GROUP,
			self::CONFIG_PREFIX . 'retention_hours',
			self::DEFAULT_RETENTION_HOURS
		);
		$retention = max(1, min(87600, $retention));

		$batch = $this->configuration->getInt(
			self::CONFIG_GROUP,
			self::CONFIG_PREFIX . 'delete_batch',
			self::DEFAULT_DELETE_BATCH
		);
		$batch = max(1, min(self::MAX_DELETE_BATCH, $batch));

		$cutoff = date('Y-m-d H:i:s', time() - $retention * 3600);
		$this->database->nonQuery(
			"DELETE FROM base3_log WHERE `timestamp` < '" . $this->database->escape($cutoff) . "' ORDER BY id ASC LIMIT " . $batch
		);
		$deleted = $this->database->affectedRows();
		$this->markRun();

		return 'Log cleanup removed ' . $deleted . ' row(s) older than ' . $cutoff . '.';
	}

	private function logTableExists(): bool {
		$row = $this->database->singleQuery("SHOW TABLES LIKE 'base3_log'");
		return $row !== null && in_array('base3_log', array_values($row), true);
	}
}
