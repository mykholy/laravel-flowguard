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
        Schema::connection(config('flowguard.connection'))->table('flowguard_outbox', function (Blueprint $table): void {
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->char('lease_hash', 64)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('available_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->string('last_failure_code', 120)->nullable();
            $table->index(['status', 'available_at', 'created_at'], 'flowguard_outbox_available_index');
        });
    }

    public function down(): void
    {
        MigrationRollbackGuard::assertSafe(config('flowguard.connection'));

        Schema::connection(config('flowguard.connection'))->table('flowguard_outbox', function (Blueprint $table): void {
            $table->dropIndex('flowguard_outbox_available_index');
            $table->dropColumn([
                'delivery_attempts',
                'lease_hash',
                'lease_expires_at',
                'available_at',
                'delivered_at',
                'last_failure_code',
            ]);
        });
    }
};
