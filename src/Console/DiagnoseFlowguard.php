<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/** Read-only migration and configuration checks; no recovery side effects. */
final class DiagnoseFlowguard extends Command
{
    protected $signature = 'flowguard:doctor';

    protected $description = 'Check Flowguard storage and required database columns';

    public function handle(DatabaseManager $database): int
    {
        /** @var string|null $name */
        $name = config('flowguard.connection');
        /** @var string $operationsTable */
        $operationsTable = config('flowguard.table', 'flowguard_operations');

        try {
            $connection = $database->connection($name);
            $driver = $connection->getDriverName();

            if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
                throw new RuntimeException('Flowguard requires a supported transactional SQL driver.');
            }

            $schema = $connection->getSchemaBuilder();
            $tables = [
                'operations' => $operationsTable,
                'evidence' => 'flowguard_reconciliation_evidence',
                'resolutions' => 'flowguard_resolution_decisions',
                'outbox' => 'flowguard_outbox',
                'inbox' => 'flowguard_inbox',
            ];

            $missing = [];

            foreach ($tables as $purpose => $table) {
                $present = $schema->hasTable($table);
                $this->line(sprintf('%-14s %s', $purpose, $present ? 'OK' : 'MISSING'));

                if (! $present) {
                    $missing[] = $table;
                }
            }

            if ($schema->hasTable('flowguard_outbox')) {
                foreach (['lease_hash', 'lease_expires_at', 'available_at', 'delivery_attempts', 'delivered_at'] as $column) {
                    if (! $schema->hasColumn('flowguard_outbox', $column)) {
                        $missing[] = 'flowguard_outbox.'.$column;
                    }
                }
            }

            $requiredUniqueIndexes = [
                $operationsTable => ['scope', 'key_hash'],
                'flowguard_outbox' => ['scope', 'event_key_hash'],
                'flowguard_inbox' => ['scope', 'event_key_hash'],
                'flowguard_resolution_decisions' => ['operation_id'],
            ];

            foreach ($requiredUniqueIndexes as $table => $columns) {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                $hasUniqueIndex = false;

                foreach ($schema->getIndexes($table) as $index) {
                    if ($index['unique'] === true && $index['columns'] === $columns) {
                        $hasUniqueIndex = true;

                        break;
                    }
                }

                if (! $hasUniqueIndex) {
                    $missing[] = $table.' UNIQUE('.implode(', ', $columns).')';
                }
            }

            // A table with the expected columns can still lose its foreign
            // key constraints after a manual schema edit or partial restore.
            // Check the actual database catalog, not migration filenames.
            $requiredForeignKeys = [
                'flowguard_reconciliation_evidence' => [
                    ['operation_id', $operationsTable],
                ],
                'flowguard_resolution_decisions' => [
                    ['operation_id', $operationsTable],
                    ['evidence_id', 'flowguard_reconciliation_evidence'],
                ],
                'flowguard_inbox' => [
                    ['outbox_id', 'flowguard_outbox'],
                ],
            ];

            foreach ($requiredForeignKeys as $table => $relations) {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                $foreignKeys = $schema->getForeignKeys($table);

                foreach ($relations as [$column, $referencedTable]) {
                    $found = false;

                    foreach ($foreignKeys as $foreignKey) {
                        if ($foreignKey['columns'] === [$column]
                            && $foreignKey['foreign_table'] === $referencedTable
                            && $foreignKey['foreign_columns'] === ['id']) {
                            $found = true;

                            break;
                        }
                    }

                    if (! $found) {
                        $missing[] = $table.' FOREIGN KEY('.$column.') REFERENCES '.$referencedTable.'(id)';
                    }
                }
            }

            if ($missing !== []) {
                $this->error('Incomplete Flowguard migrations: '.implode(', ', $missing));

                return self::FAILURE;
            }

            $unresolved = $connection->table($operationsTable)
                ->where('status', 'requires_reconciliation')->count();
            $pending = $connection->table('flowguard_outbox')
                ->where('status', 'pending')->count();

            $this->line('Database driver: '.$driver);
            $this->line('Operations requiring reconciliation: '.$unresolved);
            $this->line('Pending outbox messages: '.$pending);

            if ($driver === 'sqlite' && app()->environment('production')) {
                $this->warn('SQLite production concurrency has not been validated; use MySQL or PostgreSQL.');
            }

            $this->info('Flowguard storage checks passed. External provider policies are not validated.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Flowguard storage check failed: '.$exception::class);

            return self::FAILURE;
        }
    }
}
