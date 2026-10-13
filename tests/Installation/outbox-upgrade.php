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

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000005_add_flowguard_outbox_lease_columns.php';

if ($connection->table('flowguard_outbox')->exists()
    || $connection->table('flowguard_operations')->exists()
    || $connection->table('flowguard_inbox')->exists()) {
    throw new RuntimeException('Upgrade fixture requires an empty disposable Flowguard database.');
}

if (! $schema->hasColumn('flowguard_outbox', 'delivery_attempts')) {
    throw new RuntimeException('Lease migration was not applied initially.');
}

// Roll back only while all tables are empty. This models the preceding
// schema version; no live data or lease token is removed.
$migration->down();

if ($schema->hasColumn('flowguard_outbox', 'delivery_attempts')) {
    throw new RuntimeException('Pre-upgrade schema still contains lease columns.');
}

$eventKey = 'upgrade-'.bin2hex(random_bytes(8));
$message = $connection->transaction(static fn () => (new DatabaseOutboxRecorder($connection))
    ->record('tenant.install', $eventKey, 'migration.checked', 'upgrade-'.$eventKey));

// Forward upgrade must preserve a pending event written under the old schema.
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

echo "Forward lease migration preserved a populated pending outbox row.\n";
