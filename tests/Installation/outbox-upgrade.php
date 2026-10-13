<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException('Migration smoke test requires APP_ENV=testing.');
}

$connection = DB::connection(config('flowguard.connection'));
$schema = Schema::connection(config('flowguard.connection'));

$eventKey = 'upgrade-'.bin2hex(random_bytes(8));
$message = $connection->transaction(static fn () => (new DatabaseOutboxRecorder($connection))
    ->record('tenant.install', $eventKey, 'migration.checked', 'upgrade-'.$eventKey));

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000005_add_flowguard_outbox_lease_columns.php';

if (! $schema->hasColumn('flowguard_outbox', 'delivery_attempts')) {
    throw new RuntimeException('Lease migration was not applied initially.');
}

if ($connection->table('flowguard_outbox')->where('status', 'leased')->exists()) {
    throw new RuntimeException('Rollback precondition failed: active outbox leases exist.');
}

// Exercise only the lease-column migration against an already populated table.
// These migration calls do not change the Laravel migrations bookkeeping table.
$migration->down();

if ($schema->hasColumn('flowguard_outbox', 'delivery_attempts')) {
    throw new RuntimeException('Lease rollback left the delivery_attempts column.');
}

if ($connection->table('flowguard_outbox')->where('id', $message->id)->value('status') !== 'pending') {
    throw new RuntimeException('Lease rollback deleted or modified a pending outbox record.');
}

$migration->up();

$row = $connection->table('flowguard_outbox')->where('id', $message->id)->first();

if ($row === null || $row->status !== 'pending' || (int) $row->delivery_attempts !== 0) {
    throw new RuntimeException('Applying the lease migration lost an existing pending event.');
}

if (! $schema->hasColumn('flowguard_outbox', 'lease_hash')
    || ! $schema->hasColumn('flowguard_outbox', 'available_at')
    || ! $schema->hasColumn('flowguard_outbox', 'delivered_at')) {
    throw new RuntimeException('Required lease columns were not restored.');
}

echo "Lease migration down/up preserved a populated pending outbox row.\n";
