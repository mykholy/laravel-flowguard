<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('flowguard.connection');
        $operationsTable = (string) config('flowguard.table', 'flowguard_operations');

        Schema::connection($connection)->create('flowguard_reconciliation_evidence', function (Blueprint $table) use ($operationsTable): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->string('actor_reference', 120);
            $table->string('outcome', 32);
            $table->string('source', 120);
            $table->string('evidence_code', 120);
            $table->dateTime('observed_at', 6);
            $table->boolean('provider_reference_present')->default(false);
            $table->timestamps(6);

            $table->foreign('operation_id', 'flowguard_evidence_operation_fk')
                ->references('id')->on($operationsTable)->restrictOnDelete();
            $table->index(['operation_id', 'created_at'], 'flowguard_evidence_operation_created_index');
        });
    }

    public function down(): void
    {
        Schema::connection(config('flowguard.connection'))->dropIfExists('flowguard_reconciliation_evidence');
    }
};
