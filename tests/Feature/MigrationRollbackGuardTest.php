<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Infrastructure\MigrationRollbackGuard;

final class MigrationRollbackGuardTest extends TestCase
{
    public function test_empty_flowguard_tables_are_safe_for_a_disposable_rollback(): void
    {
        MigrationRollbackGuard::assertSafe(null);

        self::assertTrue(Schema::hasTable('flowguard_operations'));
        self::assertTrue(Schema::hasTable('flowguard_inbox'));
    }

    public function test_every_down_migration_refuses_to_drop_tables_with_live_records(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $claim = $guard->begin('tenant.1:payments', 'rollback-guard-1', ['amount_minor' => 500]);

        self::assertTrue($claim->acquired());

        $files = [
            '2026_10_12_000001_create_flowguard_operations_table.php',
            '2026_10_12_000002_create_flowguard_reconciliation_evidence_table.php',
            '2026_10_12_000003_create_flowguard_resolution_decisions_table.php',
            '2026_10_12_000004_create_flowguard_outbox_table.php',
            '2026_10_12_000005_add_flowguard_outbox_lease_columns.php',
            '2026_10_13_000001_create_flowguard_inbox_table.php',
        ];

        foreach ($files as $file) {
            $migration = require dirname(__DIR__, 2).'/database/migrations/'.$file;

            try {
                $migration->down();
                self::fail('Nonempty database rollback unexpectedly succeeded: '.$file);
            } catch (LogicException $exception) {
                self::assertStringContainsString('persisted records exist', $exception->getMessage());
            }
        }

        self::assertSame(1, DB::table('flowguard_operations')->count());
        self::assertTrue(Schema::hasColumn('flowguard_outbox', 'lease_hash'));
        self::assertTrue(Schema::hasTable('flowguard_inbox'));
        self::assertTrue(Schema::hasTable('flowguard_resolution_decisions'));
    }
}
