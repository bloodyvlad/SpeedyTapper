<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly PDO $database,
        private readonly string $migrationDirectory,
    ) {
    }

    /**
     * @param null|callable(string): void $onApplied
     * @return list<string>
     */
    public function run(?callable $onApplied = null): array
    {
        $migrationPaths = $this->migrationPaths();
        $this->ensureMigrationTable();

        if ($this->pendingPaths($migrationPaths) === []) {
            return [];
        }

        $lockName = $this->lockName();
        if (!$this->acquireLock($lockName)) {
            throw new RuntimeException('Database migration is already in progress.');
        }

        $appliedNow = [];
        try {
            // Another request may have completed the work while this request waited.
            foreach ($this->pendingPaths($migrationPaths) as $path) {
                $name = basename($path);
                $sql = file_get_contents($path);
                if ($sql === false) {
                    throw new RuntimeException('Could not read migration ' . $name);
                }

                foreach (self::splitStatements($sql) as $statement) {
                    $this->database->exec($statement);
                }

                $record = $this->database->prepare(
                    'INSERT INTO schema_migrations (migration) VALUES (:migration)'
                );
                $record->execute(['migration' => $name]);
                $appliedNow[] = $name;
                if ($onApplied !== null) {
                    $onApplied($name);
                }
            }
        } catch (Throwable $error) {
            // MySQL commits most DDL implicitly, but a migration may also open
            // an explicit transaction for its data changes. Never return a
            // pooled/persistent connection with that transaction still live
            // after a failed statement or callback.
            if ($this->database->inTransaction()) {
                try {
                    $this->database->rollBack();
                } catch (Throwable $rollbackError) {
                    error_log(
                        'SpeedyTapper migration rollback failed: '
                        . $rollbackError->getMessage()
                    );
                }
            }
            throw $error;
        } finally {
            $this->releaseLock($lockName);
        }

        return $appliedNow;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(array_map('trim', $statements), static fn (string $statement): bool => $statement !== ''));
    }

    /** @return list<string> */
    private function migrationPaths(): array
    {
        $paths = glob(rtrim($this->migrationDirectory, '/') . '/*.sql') ?: [];
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new RuntimeException('No database migrations were found.');
        }

        foreach ($paths as $path) {
            if (!preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', basename($path))) {
                throw new RuntimeException('Invalid migration filename ' . basename($path));
            }
        }

        return array_values($paths);
    }

    private function ensureMigrationTable(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'migration VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
            . 'applied_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function pendingPaths(array $paths): array
    {
        $applied = $this->database
            ->query('SELECT migration FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);
        $known = array_fill_keys(array_map('strval', $applied), true);
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => !isset($known[basename($path)]),
        ));
    }

    private function lockName(): string
    {
        $databaseName = (string) $this->database->query('SELECT DATABASE()')->fetchColumn();
        return 'speedytapper:migrations:' . substr(hash('sha256', $databaseName), 0, 32);
    }

    private function acquireLock(string $lockName): bool
    {
        $statement = $this->database->prepare(
            'SELECT GET_LOCK(:lock_name, ' . self::LOCK_TIMEOUT_SECONDS . ')'
        );
        $statement->bindValue(':lock_name', $lockName);
        $statement->execute();
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock(string $lockName): void
    {
        try {
            $statement = $this->database->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $lockName]);
        } catch (Throwable $error) {
            error_log('SpeedyTapper migration lock release failed: ' . $error->getMessage());
        }
    }
}
