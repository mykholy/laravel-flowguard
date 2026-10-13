<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException('Migration smoke test requires APP_ENV=testing.');
}

// Use an isolated in-memory database so a populated production-like migration
// cannot accidentally alter the real clean-install application's schema.
config()->set('database.connections.flowguard_upgrade_smoke', [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
    'foreign_key_constraints' => true,
]);
config()->set('flowguard.connection', 'flowguard_upgrade_smoke');

$connection = DB::connection('flowguard_upgrade_smoke');
$schema = Schema::connection('flowguard_upgrade_smoke');

$schema->create('flowguard_outbox', static function (Blueprint $table): void {
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

$key = 'upgrade-'.bin2hex(random_bytes(8));
$message = $connection->transaction(static fn () => (new DatabaseOutboxRecorder($connection))
    ->record('tenant.install', $key, 'migration.checked', 'upgrade-'.$key));

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000005_add_flowguard_outbox_lease_columns.php';

if ($schema->hasColumn('flowguard_outbox', 'delivery_attempts')) {
    throw new RuntimeException('Expected pre-lease schema in isolated test database.');
}

// Verify the forward upgrade does not remove the already durable event.
$migration->up();

$row = $connection->table('flowguard_outbox')->where('id', $message->id)->first();

if ($row === null || $row->status !== 'pending' || (int) $row->delivery_attempts !== 0) {
    throw new RuntimeException('Forward lease upgrade lost a pending event.');
}

if (! $schema->hasColumn('flowguard_outbox', 'lease_hash')
    || ! $schema->hasColumn('flowguard_outbox', 'available_at')
    || ! $schema->hasColumn('flowguard_outbox', 'delivered_at')) {
    throw new RuntimeException('Forward upgrade did not install required lease columns.');
}

// A down migration must now refuse destructive changes to populated storage.
try {
    $migration->down();
    throw new RuntimeException('Nonempty rollback unexpectedly succeeded.');
} catch (LogicException $exception) {
    if (! str_contains($exception->getMessage(), 'persisted records exist')) {
        throw $exception;
    }
}

if (! $connection->table('flowguard_outbox')->where('id', $message->id)->exists()
    || ! $schema->hasColumn('flowguard_outbox', 'lease_hash')) {
    throw new RuntimeException('Rejected downgrade damaged the stored event.');
}

echo "Forward lease upgrade retained a pending event; populated downgrade was safely rejected.\n";
