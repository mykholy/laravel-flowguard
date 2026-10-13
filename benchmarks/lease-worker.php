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

    $deadline = microtime(true) + 180;
    while (file_exists($gate) === false) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Stress-test start gate timed out.');
        }

        usleep(10000);
    }

    $started = hrtime(true);
    $store = new DatabaseOutboxLeaseStore(flowguardConcurrencyConnection(), $table);
    // SKIP LOCKED can return no row while peers briefly hold all candidates.
    // This is a poll, not an assertion that the durable queue is empty.
    $reserveDeadline = microtime(true) + 10;
    $lease = null;

    do {
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
