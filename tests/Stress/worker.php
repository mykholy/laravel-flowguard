<?php

declare(strict_types=1);

use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;

require dirname(__DIR__).'/Concurrency/bootstrap.php';

try {
    $table = getenv('FG_STRESS_TABLE');
    $gate = getenv('FG_STRESS_GATE');

    if (! is_string($table) || preg_match('/^fg_stress_[a-f0-9]{12}$/D', $table) !== 1) {
        throw new InvalidArgumentException('Invalid disposable stress table.');
    }

    if (! is_string($gate) || $gate === '') {
        throw new InvalidArgumentException('Missing worker start gate.');
    }

    $deadline = microtime(true) + 60;

    while (! file_exists($gate)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker did not receive start signal.');
        }

        usleep(10000);
    }

    $store = new DatabaseOutboxLeaseStore(flowguardConcurrencyConnection(), $table, 3);
    $handled = 0;
    $started = hrtime(true);

    while (true) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker exceeded the stress-test deadline.');
        }

        $lease = $store->reserve(60);

        if ($lease === null) {
            break;
        }

        $store->acknowledge($lease);
        $handled++;
    }

    echo json_encode([
        'handled' => $handled,
        'elapsed_ms' => (hrtime(true) - $started) / 1000000,
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
