<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

require dirname(__DIR__).'/Concurrency/bootstrap.php';

if (getenv('FLOWGUARD_STRESS_CONFIRM') !== 'disposable-only') {
    throw new InvalidArgumentException('Stress tests require FLOWGUARD_STRESS_CONFIRM=disposable-only.');
}

$workers = filter_var(getenv('FLOWGUARD_STRESS_WORKERS') ?: '10', FILTER_VALIDATE_INT);
$messagesPerWorker = filter_var(getenv('FLOWGUARD_STRESS_MESSAGES_PER_WORKER') ?: '2', FILTER_VALIDATE_INT);

if (! in_array($workers, [10, 100, 500], true) ||
    ! is_int($messagesPerWorker) || $messagesPerWorker < 1 || $messagesPerWorker > 10) {
    throw new InvalidArgumentException('Allowed workers: 10, 100, 500; messages per worker: 1..10.');
}

if (! function_exists('proc_open')) {
    throw new RuntimeException('Independent-process stress tests require proc_open.');
}

$db = flowguardConcurrencyConnection();
$table = 'fg_stress_'.bin2hex(random_bytes(6));
$gate = sys_get_temp_dir().'/flowguard-stress-'.bin2hex(random_bytes(12));
$total = $workers * $messagesPerWorker;
$processes = [];
$results = [];

$db->getSchemaBuilder()->create($table, static function (Blueprint $schema): void {
    $schema->uuid('id')->primary();
    $schema->string('scope', 120);
    $schema->char('event_key_hash', 64);
    $schema->string('topic', 120);
    $schema->string('subject_ref', 128);
    $schema->string('status', 20)->default('pending');
    $schema->unsignedInteger('delivery_attempts')->default(0);
    $schema->char('lease_hash', 64)->nullable();
    $schema->dateTime('lease_expires_at', 6)->nullable();
    $schema->dateTime('available_at', 6)->nullable();
    $schema->dateTime('delivered_at', 6)->nullable();
    $schema->string('last_failure_code', 120)->nullable();
    $schema->timestamps(6);
    $schema->unique(['scope', 'event_key_hash']);
    $schema->index(['status', 'created_at']);
});

try {
    $recorder = new DatabaseOutboxRecorder($db, $table);

    for ($i = 0; $i < $total; $i++) {
        $db->transaction(static function () use ($recorder, $i): void {
            $recorder->record('tenant.stress', 'event-'.$i, 'stress.recorded', 'subject-'.$i);
        });
    }

    $env = array_merge($_ENV, [
        'FLOWGUARD_CONCURRENCY_TEST' => '1',
        'FLOWGUARD_TEST_DATABASE' => 'flowguard_ci',
        'FLOWGUARD_TEST_DRIVER' => (string) getenv('FLOWGUARD_TEST_DRIVER'),
        'FLOWGUARD_TEST_HOST' => (string) getenv('FLOWGUARD_TEST_HOST'),
        'FLOWGUARD_TEST_PORT' => (string) getenv('FLOWGUARD_TEST_PORT'),
        'FLOWGUARD_TEST_USERNAME' => (string) getenv('FLOWGUARD_TEST_USERNAME'),
        'FLOWGUARD_TEST_PASSWORD' => (string) getenv('FLOWGUARD_TEST_PASSWORD'),
        'FG_STRESS_TABLE' => $table,
        'FG_STRESS_GATE' => $gate,
    ]);

    $started = hrtime(true);

    for ($i = 0; $i < $workers; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/worker.php'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2), $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start stress worker '.$i.'.');
        }

        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    touch($gate);
    $failures = [];

    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            $failures[] = trim((string) $error) ?: 'Worker failed without diagnostic output.';
            continue;
        }

        $results[] = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
    }

    $processes = [];
    $elapsedSeconds = (hrtime(true) - $started) / 1000000000;

    $delivered = $db->table($table)->where('status', 'delivered')->count();
    $duplicates = $db->table($table)->where('delivery_attempts', '<>', 1)->count();
    $unfinished = $db->table($table)->where('status', '<>', 'delivered')->count();
    $acknowledgements = array_sum(array_column($results, 'handled'));

    $report = [
        'database' => $db->getDriverName(),
        'workers' => $workers,
        'messages' => $total,
        'delivered' => $delivered,
        'duplicate_attempts' => $duplicates,
        'unfinished' => $unfinished,
        'worker_failures' => count($failures),
        'acknowledgements' => $acknowledgements,
        'elapsed_seconds' => round($elapsedSeconds, 3),
        'deliveries_per_second' => round($total / max(0.001, $elapsedSeconds), 2),
        'environment' => 'disposable flowguard_ci database; no external publishing',
    ];

    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

    if ($failures !== [] || count($results) !== $workers ||
        $delivered !== $total || $duplicates !== 0 ||
        $unfinished !== 0 || $acknowledgements !== $total) {
        fwrite(STDERR, "Outbox stress correctness check failed.\n");
        foreach (array_slice($failures, 0, 5) as $failure) {
            fwrite(STDERR, $failure."\n");
        }
        exit(1);
    }
} finally {
    @unlink($gate);

    foreach ($processes as [$process, $pipes]) {
        if (is_resource($process)) {
            proc_terminate($process);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    $db->getSchemaBuilder()->dropIfExists($table);
}
