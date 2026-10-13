<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;

require dirname(__DIR__).'/Concurrency/bootstrap.php';

if (getenv('FLOWGUARD_RESTORE_CONFIRM') !== 'disposable-only') {
    throw new InvalidArgumentException('Relational restore requires explicit disposable-only confirmation.');
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
    throw new RuntimeException('Refusing unexpected restore target.');
}

$operations = 'fg_restore_operation';
$evidence = 'fg_restore_evidence';
$resolutions = 'fg_restore_resolution';
$operationId = '44444444-4444-4444-8444-444444444444';
$evidenceId = '55555555-5555-4555-8555-555555555555';
$resolutionId = '66666666-6666-4666-8666-666666666666';

if ($mode === 'prepare') {
    $schema = $db->getSchemaBuilder();

    foreach ([$operations, $evidence, $resolutions] as $table) {
        if ($schema->hasTable($table)) {
            throw new RuntimeException('Restore fixture already exists; refusing to overwrite it.');
        }
    }

    $schema->create($operations, static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('scope', 120);
        $table->string('status', 32);
        $table->timestamps(6);
    });

    $schema->create($evidence, static function (Blueprint $table) use ($operations): void {
        $table->uuid('id')->primary();
        $table->uuid('operation_id');
        $table->string('outcome', 32);
        $table->timestamps(6);
        $table->foreign('operation_id')->references('id')->on($operations)->restrictOnDelete();
    });

    $schema->create($resolutions, static function (Blueprint $table) use ($operations, $evidence): void {
        $table->uuid('id')->primary();
        $table->uuid('operation_id')->unique();
        $table->uuid('evidence_id');
        $table->string('status', 32);
        $table->timestamps(6);
        $table->foreign('operation_id')->references('id')->on($operations)->restrictOnDelete();
        $table->foreign('evidence_id')->references('id')->on($evidence)->restrictOnDelete();
    });

    $db->transaction(static function () use (
        $db, $operations, $evidence, $resolutions, $operationId, $evidenceId, $resolutionId,
    ): void {
        $now = now();
        $db->table($operations)->insert([
            'id' => $operationId,
            'scope' => 'tenant.restore:payments',
            'status' => 'reconciled_success',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table($evidence)->insert([
            'id' => $evidenceId,
            'operation_id' => $operationId,
            'outcome' => 'confirmed_success',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table($resolutions)->insert([
            'id' => $resolutionId,
            'operation_id' => $operationId,
            'evidence_id' => $evidenceId,
            'status' => 'reconciled_success',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });

    echo "Prepared a linked operation, provider evidence and resolution in disposable SQL.\n";
    exit(0);
}

if ($db->table($operations)->where('id', $operationId)->where('status', 'reconciled_success')->count() !== 1 ||
    $db->table($evidence)->where('id', $evidenceId)->where('operation_id', $operationId)->count() !== 1 ||
    $db->table($resolutions)->where('id', $resolutionId)->where('evidence_id', $evidenceId)->count() !== 1) {
    throw new RuntimeException('Restored operation/evidence/decision graph is inconsistent.');
}

// A native restore must retain the actual FOREIGN KEY, not just matching IDs.
try {
    $db->table($operations)->where('id', $operationId)->delete();
    throw new RuntimeException('Restored FK unexpectedly allowed deleting an audited operation.');
} catch (QueryException) {
    // Restrictive FK prevented a destructive parent delete.
}

if ($db->table($operations)->where('id', $operationId)->count() !== 1) {
    throw new RuntimeException('Foreign key test changed the restored operation.');
}

echo "Native restore preserved linked operation/evidence/resolution records and restrictive foreign keys.\n";
