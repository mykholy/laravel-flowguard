<?php

declare(strict_types=1);

use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Infrastructure\DatabaseOperationStore;

require __DIR__.'/bootstrap.php';

try {
    $table = getenv('FLOWGUARD_TEST_TABLE');
    $gate = getenv('FLOWGUARD_TEST_GATE');
    $key = getenv('FLOWGUARD_TEST_KEY');
    if (! is_string($table) || ! preg_match('/^fg_race_[a-f0-9]{12}$/D', $table) || ! is_string($gate) || ! is_string($key)) {
        throw new InvalidArgumentException('Invalid worker test inputs.');
    }

    // Gate all processes just before the unique-key contention point.
    $deadline = microtime(true) + 20;
    while (! file_exists($gate)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for test start gate.');
        }
        usleep(10_000);
    }

    $store = new DatabaseOperationStore(flowguardConcurrencyConnection(), $table);
    $entry = $store->acquire(OperationIdentity::from('payments.capture', $key, ['amount_minor' => 1200, 'currency' => 'EGP']));
    echo json_encode(['acquired' => $entry->acquired(), 'id' => $entry->operation->id, 'status' => $entry->operation->status->value], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
