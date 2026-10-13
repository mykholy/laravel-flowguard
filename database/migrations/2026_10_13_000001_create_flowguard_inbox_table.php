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
        Schema::connection(config('flowguard.connection'))->create('flowguard_inbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->uuid('outbox_id');
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash'], 'flowguard_inbox_scope_event_unique');
            $table->foreign('outbox_id', 'flowguard_inbox_outbox_fk')
                ->references('id')->on('flowguard_outbox')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        MigrationRollbackGuard::assertSafe(config('flowguard.connection'));

        Schema::connection(config('flowguard.connection'))->dropIfExists('flowguard_inbox');
    }
};
