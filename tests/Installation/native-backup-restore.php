<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;

require dirname(__DIR__).'/Concurrency/bootstrap.php';

if (getenv('FLOWGUARD_RESTORE_CONFIRM') !== 'disposable-only') {
    throw new InvalidArgumentException('Restore drill requires explicit disposable-only confirmation.');
}

$mode = $argv[1] ?? '';
if (! in_array($mode, ['prepare', 'verify'], true)) {
    throw new InvalidArgumentException('Expected prepare or verify mode.');
}

$source = flowguardConcurrencyConnection();
$databaseName = $mode === 'prepare' ? 'flowguard_ci' : 'flowguard_restore_ci';

if ($mode === 'prepare') {
    $db = $source;
} else {
    $config = $source->getConfig();
    $config['database'] = $databaseName;
    $capsule = new Capsule;
    $capsule->addConnection($config);
    $db = $capsule->getConnection();
}

if ($db->getDatabaseName() !== $databaseName) {
    throw new RuntimeException('Refusing to use an unexpected database.');
}

$table = 'fg_restore_fixture';
$pendingId = '11111111-1111-4111-8111-111111111111';
$leasedId = '22222222-2222-4222-8222-222222222222';
$deliveredId = '33333333-3333-4333-8333-333333333333';

if ($mode === 'prepare') {
    if ($db->getSchemaBuilder()->hasTable($table)) {
        throw new RuntimeException('The restore fixture table already exists; do not overwrite it.');
    }

    $db->getSchemaBuilder()->create($table, static function (Blueprint $schema): void {
        $schema->uuid('id')->primary();
        $schema->string('scope', 120);
        $schema->char('event_key_hash', 64);
        $schema->string('topic', 120);
        $schema->string('subject_ref', 128);
        $schema->string('status', 20);
        $schema->unsignedInteger('delivery_attempts')->default(0);
        $schema->char('lease_hash', 64)->nullable();
        $schema->dateTime('lease_expires_at', 6)->nullable();
        $schema->dateTime('available_at', 6)->nullable();
        $schema->dateTime('delivered_at', 6)->nullable();
        $schema->string('last_failure_code', 120)->nullable();
        $schema->timestamps(6);
        $schema->unique(['scope', 'event_key_hash']);
    });

    $now = now();

    $db->transaction(static function () use ($db, $table, $now, $pendingId, $leasedId, $deliveredId): void {
        foreach ([
            [$pendingId, 'pending', 0],
            [$leasedId, 'leased', 2],
            [$deliveredId, 'delivered', 1],
        ] as [$id, $status, $attempts]) {
            $db->table($table)->insert([
                'id' => $id,
                'scope' => 'tenant.restore:outbox',
                'event_key_hash' => hash('sha256', $id),
                'topic' => 'restore.probe',
                'subject_ref' => 'subject-'.$attempts,
                'status' => $status,
                'delivery_attempts' => $attempts,
                'lease_hash' => $status === 'leased' ? hash('sha256', 'fixture-only-token') : null,
                'lease_expires_at' => $status === 'leased' ? $now->copy()->addHour() : null,
                'delivered_at' => $status === 'delivered' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    });

    echo "Prepared three outbox states in disposable flowguard_ci.\n";
    exit(0);
}

if (! $db->getSchemaBuilder()->hasTable($table)) {
    throw new RuntimeException('Restored outbox table is missing.');
}

if ($db->table($table)->count() !== 3 ||
    $db->table($table)->where('id', $pendingId)->where('status', 'pending')->count() !== 1 ||
    $db->table($table)->where('id', $leasedId)->where('status', 'leased')->where('delivery_attempts', 2)->count() !== 1 ||
    $db->table($table)->where('id', $deliveredId)->where('status', 'delivered')->where('delivery_attempts', 1)->count() !== 1) {
    throw new RuntimeException('Backup restoration did not preserve all outbox states.');
}

$store = new DatabaseOutboxLeaseStore($db, $table);
$lease = $store->reserve(60);

if ($lease === null || $lease->id !== $pendingId || $lease->attempt !== 1) {
    throw new RuntimeException('Pending message could not be resumed after restoration.');
}

$store->acknowledge($lease);

if ($db->table($table)->where('status', 'delivered')->count() !== 2 ||
    $db->table($table)->where('id', $leasedId)->where('status', 'leased')->count() !== 1) {
    throw new RuntimeException('Recovery modified an unrelated restored delivery.');
}

echo "Native database restore preserved pending, leased and delivered rows; pending delivery was resumed.\n";
