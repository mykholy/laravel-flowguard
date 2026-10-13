<?php

declare(strict_types=1);

use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;

require dirname(__DIR__).'/tests/Concurrency/bootstrap.php';

try {
    $table = getenv('FG_STRESS_TABLE');
    $gate = getenv('FG_STRESS_GATE');

    if (! is_string($table) || preg_match('/^fg_stress_[a-f0-9]{12}$/D', $table) !== 1
        || ! is_string($gate) || $gate === '') {
        throw new InvalidArgumentException('Missing validated stress-test inputs.');
    }

    $deadline = microtime(true) + 180;
    while (! file_exists($gate)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Stress-test start gate timed out.');
        }

        usleep(10000);
    }

    $started = hrtime(true);
    $store = new DatabaseOutboxLeaseStore(flowguardConcurrencyConnection(), $table);
    $lease = $store->reserve(60);

    if ($lease === null) {
        throw new RuntimeException('No eligible outbox message found.');
    }

    $store->acknowledge($lease);

    echo json_encode([
        'id' => $lease->id,
        'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    $sqlstate = $exception instanceof \Illuminate\Database\QueryException
        ? (string) ($exception->errorInfo[0] ?? 'unknown')
        : 'none';
    $driverCode = $exception instanceof \Illuminate\Database\QueryException
        ? (string) ($exception->errorInfo[1] ?? 'unknown')
        : 'none';

    // Classification only: SQL text and raw provider/database messages are omitted.
    fwrite(STDERR, $exception::class.'|'.$sqlstate.'|'.$driverCode."\n");
    exit(1);
}
