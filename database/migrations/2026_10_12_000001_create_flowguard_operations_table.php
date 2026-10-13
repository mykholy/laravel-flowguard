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
        Schema::connection(config('flowguard.connection'))->create(config('flowguard.table', 'flowguard_operations'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('key_hash', 64);
            $table->char('payload_hash', 64);
            $table->char('claim_hash', 64);
            $table->string('status', 32);
            $table->json('result_json')->nullable();
            $table->string('reconciliation_reason', 120)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'key_hash'], 'flowguard_scope_key_unique');
            $table->index(['status', 'created_at'], 'flowguard_status_created_index');
        });
    }

    public function down(): void
    {
        MigrationRollbackGuard::assertSafe(config('flowguard.connection'));

        Schema::connection(config('flowguard.connection'))->dropIfExists(config('flowguard.table', 'flowguard_operations'));
    }
};
