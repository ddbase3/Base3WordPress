# BASE3 database logging for the WordPress integration

The project module `Base3WordPressLab` binds `Base3\Logger\ScopedDatabaseLogger\ScopedDatabaseLogger` as `ILogger` with the existing `IDatabase` service. The existing BASE3 implementation creates the shared `base3_log` table on first access. The log display reads the same table through `ILogger::getScopes()` and `ILogger::getLogs()`.

WordPress/PHP error logging remains independent. Old entries in the PHP error log are not migrated into `base3_log`.

## Log cleanup job

`Base3WordPress\Job\Base3LogCleanupJob` is discovered as `base3logcleanupjob` and uses `dailywindowjobpolicy` (02:00-04:00 in the PHP process timezone). **It is disabled by default.** Enable it via the existing BASE3 Jobs administration display only after verifying the retention requirements.

Configuration group `job`:

| Key | Default | Meaning |
| --- | --- | --- |
| `base3logcleanupjob.active` | `0` | Disable/enable the cleanup job |
| `base3logcleanupjob.priority` | `1` | Job priority, 1-10 |
| `base3logcleanupjob.retention_hours` | `48` | Age threshold in hours |
| `base3logcleanupjob.delete_batch` | `20000` | Maximum deleted rows per successful daily run (capped at 100000) |

Rows are deleted in ascending ID order. The job marks the daily policy as completed only after successful execution. No database schema is dropped.

## Obsolete adapter removal

The previous `WordPressLogger.php` can be deleted after the updated two plugin classes are installed and the database logger is verified. ZIP extraction does not delete old files.

From `modules/Base3WordPress/`:

```sh
sh deploy/cleanup/remove-legacy-wordpress-logger.sh
sh deploy/cleanup/remove-legacy-wordpress-logger.sh --delete
```

The script validates the exact reviewed file hash and searches for remaining PHP references in the native WordPress plugin. It does not delete any PHP/WordPress log files or historical log records. After successful deletion, commit the removed adapter separately in the `Base3WordPress` Git repository.
