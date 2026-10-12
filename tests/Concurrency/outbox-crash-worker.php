<?php

declare(strict_types=1);

use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;

require __DIR__.'/bootstrap.php';

try {
    $table = getenv('FG_CRASH_OUTBOX_TABLE');
    $ready = getenv('FG_CRASH_READY_PATH');

    if (! is_string($table) || preg_match('/^fg_outbox_[a-f0-9]{12}$/D', $table) !== 1) {
        throw new InvalidArgumentException('Invalid test outbox table.');
    }

    if (! is_string($ready) || $ready === '') {
        throw new InvalidArgumentException('Missing crash test synchronization file.');
    }

    $store = new DatabaseOutboxLeaseStore(flowguardConcurrencyConnection(), $table);
    $lease = $store->reserve(30);

    if ($lease === null) {
        throw new RuntimeException('Expected a pending message.');
    }

    if (file_put_contents($ready, 'reserved', LOCK_EX) === false) {
        throw new RuntimeException('Could not signal acquired lease.');
    }

    // The parent terminates this process before acknowledgement.
    sleep(30);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
