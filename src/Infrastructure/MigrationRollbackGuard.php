<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Refuses destructive migration rollback while Flowguard owns any rows.
 *
 * An empty-table rollback is for disposable or newly provisioned databases.
 * Restore production schemas from reviewed migrations and backups instead.
 */
final class MigrationRollbackGuard
{
    public static function assertSafe(?string $connectionName): void
    {
        $schema = Schema::connection($connectionName);
        $connection = DB::connection($connectionName);

        /** @var string $operations */
        $operations = config('flowguard.table', 'flowguard_operations');

        $tables = [
            $operations,
            'flowguard_reconciliation_evidence',
            'flowguard_resolution_decisions',
            'flowguard_outbox',
            'flowguard_inbox',
        ];

        foreach ($tables as $table) {
            if ($schema->hasTable($table) && $connection->table($table)->exists()) {
                throw new LogicException(
                    'Flowguard migrations cannot roll back while persisted records exist. Back up and follow a reviewed archival plan.',
                );
            }
        }
    }
}
