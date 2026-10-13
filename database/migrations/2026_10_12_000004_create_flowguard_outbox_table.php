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
        Schema::connection(config('flowguard.connection'))->create('flowguard_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->string('topic', 120);
            $table->string('subject_ref', 128);
            $table->string('status', 20)->default('pending');
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash'], 'flowguard_outbox_scope_event_unique');
            $table->index(['status', 'created_at'], 'flowguard_outbox_pending_index');
        });
    }

    public function down(): void
    {
        MigrationRollbackGuard::assertSafe(config('flowguard.connection'));

        Schema::connection(config('flowguard.connection'))->dropIfExists('flowguard_outbox');
    }
};
