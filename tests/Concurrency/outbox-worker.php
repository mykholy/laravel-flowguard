<?php

declare(strict_types=1);

use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;

require __DIR__.'/bootstrap.php';

try {
    $table = getenv('FG_OUTBOX_RACE_TABLE');
    $gate = getenv('FG_OUTBOX_RACE_GATE');

    if (! is_string($table) || preg_match('/^fg_outbox_[a-f0-9]{12}$/D', $table) !== 1 || ! is_string($gate)) {
        throw new InvalidArgumentException('Invalid outbox race input.');
    }

    $deadline = microtime(true) + 20;

    while (! file_exists($gate)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Outbox test start gate timed out.');
        }
        usleep(10000);
    }

    $lease = (new DatabaseOutboxLeaseStore(flowguardConcurrencyConnection(), $table))->reserve(60);
    echo json_encode(['reserved' => $lease !== null, 'id' => $lease?->id], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
