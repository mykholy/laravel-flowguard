<?php

declare(strict_types=1);

use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;

require __DIR__.'/bootstrap.php';

try {
    $inbox = getenv('FG_INBOX_TABLE');
    $outbox = getenv('FG_OUTBOX_TABLE');
    $gate = getenv('FG_INBOX_GATE');

    foreach ([$inbox, $outbox] as $table) {
        if (! is_string($table) || preg_match('/^fg_(inbox|outbox)_[a-f0-9]{12}$/D', $table) !== 1) {
            throw new InvalidArgumentException('Invalid inbox test table.');
        }
    }

    if (! is_string($gate)) {
        throw new InvalidArgumentException('Missing test gate.');
    }

    $deadline = microtime(true) + 20;
    while (! file_exists($gate)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Inbox test worker timed out.');
        }
        usleep(10000);
    }

    $recorder = new DatabaseInboxRecorder(flowguardConcurrencyConnection(), $inbox, $outbox);
    $receipt = $recorder->receive(
        'tenant.42:webhooks', 'same-provider-event', ['type' => 'captured', 'amount' => 500],
        'payment.received', 'payment-500',
    );

    echo json_encode(['duplicate' => $receipt->duplicate], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
