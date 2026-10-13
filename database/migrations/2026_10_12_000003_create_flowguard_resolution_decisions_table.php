<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mykholy\Flowguard\Infrastructure\MigrationRollbackGuard;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('flowguard.connection');
        $operationsTable = (string) config('flowguard.table', 'flowguard_operations');

        Schema::connection($connection)->create('flowguard_resolution_decisions', function (Blueprint $table) use ($operationsTable): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->unique('flowguard_resolution_operation_unique');
            $table->uuid('evidence_id');
            $table->string('status', 32);
            $table->string('initiator_reference', 120);
            $table->string('approver_reference', 120);
            $table->string('approval_reference', 128);
            $table->timestamps(6);
            $table->foreign('operation_id', 'flowguard_resolution_operation_fk')
                ->references('id')->on($operationsTable)->restrictOnDelete();
            $table->foreign('evidence_id', 'flowguard_resolution_evidence_fk')
                ->references('id')->on('flowguard_reconciliation_evidence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        MigrationRollbackGuard::assertSafe(config('flowguard.connection'));

        Schema::connection(config('flowguard.connection'))->dropIfExists('flowguard_resolution_decisions');
    }
};
