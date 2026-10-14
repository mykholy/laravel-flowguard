<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;

require dirname(__DIR__).'/tests/Concurrency/bootstrap.php';

try {
    $table = getenv('FG_STRESS_TABLE');
    $gate = getenv('FG_STRESS_GATE');

    if (is_string($table) === false || preg_match('/^fg_stress_[a-f0-9]{12}$/D', $table) !== 1
        || is_string($gate) === false || $gate === '') {
        throw new InvalidArgumentException('Missing validated stress-test inputs.');
    }

    $slot = getenv('FG_STRESS_SLOT');
    if (is_string($slot) === false || preg_match('/^[0-9]{1,3}$/D', $slot) !== 1) {
        throw new InvalidArgumentException('Missing worker slot.');
    }

    // Bootstrap and connect before signalling readiness to the parent.
    $connection = flowguardConcurrencyConnection();
    $connection->getPdo();
    $store = new DatabaseOutboxLeaseStore($connection, $table);
    if (file_put_contents($gate.'.ready.'.$slot, 'ready') === false) {
        throw new RuntimeException('Could not signal worker readiness.');
    }

    $deadline = microtime(true) + 180;
    while (file_exists($gate) === false) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Stress-test start gate timed out.');
        }

        usleep(10000);
    }

    $started = hrtime(true);
    // SKIP LOCKED can return no row while peers briefly hold all candidates.
    // This is a poll, not an assertion that the durable queue is empty.
    $reserveDeadline = microtime(true) + 10;
    $lease = null;
    $polls = 0;

    do {
        $polls++;
        $lease = $store->reserve(60);

        if ($lease !== null) {
            break;
        }

        usleep(10000);
    } while (microtime(true) < $reserveDeadline);

    if ($lease === null) {
        throw new RuntimeException('No eligible outbox message became available before deadline.');
    }

    $store->acknowledge($lease);

    echo json_encode([
        'id' => $lease->id,
        'reserve_polls' => $polls,
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    $sqlstate = $exception instanceof QueryException
        ? (string) ($exception->errorInfo[0] ?? 'unknown')
        : 'none';
    $driverCode = $exception instanceof QueryException
        ? (string) ($exception->errorInfo[1] ?? 'unknown')
        : 'none';

    // Classification only: SQL text and raw provider/database messages are omitted.
    fwrite(STDERR, $exception::class.'|'.$sqlstate.'|'.$driverCode."\n");

    exit(1);
}
