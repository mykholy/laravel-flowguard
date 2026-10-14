<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

require dirname(__DIR__).'/tests/Concurrency/bootstrap.php';

$raw = getenv('FLOWGUARD_STRESS_WORKERS');
$workersRequested = filter_var($raw === false ? '10' : $raw, FILTER_VALIDATE_INT);

if (is_int($workersRequested) === false || $workersRequested < 2 || $workersRequested > 500) {
    throw new InvalidArgumentException('Stress worker count must be 2..500.');
}

if ($workersRequested > 50 && getenv('FLOWGUARD_STRESS_LARGE_RUN') !== '1') {
    throw new InvalidArgumentException('More than 50 local processes requires explicit FLOWGUARD_STRESS_LARGE_RUN=1.');
}

if (function_exists('proc_open') === false) {
    throw new RuntimeException('Independent-process stress testing requires proc_open.');
}

// bootstrap.php refuses non-disposable or non-opted-in database targets.
$rawBudget = getenv('FLOWGUARD_STRESS_MAX_P99_MS');
$latencyBudget = $rawBudget === false ? null : filter_var($rawBudget, FILTER_VALIDATE_INT);
if ($latencyBudget !== null && (! is_int($latencyBudget) || $latencyBudget < 1 || $latencyBudget > 180000)) {
    throw new InvalidArgumentException('Stress p99 budget must be 1..180000 milliseconds.');
}

$db = flowguardConcurrencyConnection();
$table = 'fg_stress_'.bin2hex(random_bytes(6));
$gate = sys_get_temp_dir().'/flowguard-stress-'.bin2hex(random_bytes(12));
$children = [];
$failures = [];
$samples = [];
$ids = [];
$polls = [];
$memory = [];
$started = hrtime(true);

$db->getSchemaBuilder()->create($table, static function (Blueprint $schema): void {
    $schema->uuid('id')->primary();
    $schema->string('scope', 120);
    $schema->char('event_key_hash', 64);
    $schema->string('topic', 120);
    $schema->string('subject_ref', 128);
    $schema->string('status', 20);
    $schema->unsignedInteger('delivery_attempts')->default(0);
    $schema->char('lease_hash', 64)->nullable();
    $schema->dateTime('lease_expires_at', 6)->nullable();
    $schema->dateTime('available_at', 6)->nullable();
    $schema->dateTime('delivered_at', 6)->nullable();
    $schema->string('last_failure_code', 120)->nullable();
    $schema->timestamps(6);
    $schema->unique(['scope', 'event_key_hash']);
    $schema->index(['status', 'available_at', 'created_at']);
});

try {
    $recorder = new DatabaseOutboxRecorder($db, $table);

    $db->transaction(static function () use ($recorder, $workersRequested): void {
        for ($n = 0; $n < $workersRequested; $n++) {
            $recorder->record('tenant.stress:outbox', 'event-'.$n, 'stress.record', 'subject-'.$n);
        }
    });

    // No process can reserve before all peers are launched.
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

    for ($i = 0; $i < $workersRequested; $i++) {
        $env['FG_STRESS_SLOT'] = (string) $i;
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/lease-worker.php'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__), $env);

        if (is_resource($process) === false) {
            throw new RuntimeException('Could not start independent lease process.');
        }

        fclose($pipes[0]);
        $children[] = ['process' => $process, 'pipes' => $pipes];
    }

    // proc_open returning does not mean PHP bootstrap or a DB connection is ready.
    $readyDeadline = microtime(true) + 180;
    do {
        $ready = 0;
        for ($i = 0; $i < $workersRequested; $i++) {
            $ready += is_file($gate.'.ready.'.$i) ? 1 : 0;
        }
        if ($ready === $workersRequested) {
            break;
        }
        foreach ($children as $child) {
            if (proc_get_status($child['process'])['running'] === false) {
                throw new RuntimeException('A worker exited before the synchronized start; workload invalid.');
            }
        }
        if (microtime(true) >= $readyDeadline) {
            throw new RuntimeException('Not all workers became ready; workload invalid.');
        }
        usleep(10000);
    } while (true);

    $deliveryStarted = hrtime(true);
    touch($gate);

    foreach ($children as $child) {
        $process = $child['process'];
        $pipes = $child['pipes'];
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        if ($exit !== 0) {
            $failures[] = trim((string) $error);

            continue;
        }

        try {
            $row = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($row) === false || is_string($row['id'] ?? null) === false
                || is_numeric($row['elapsed_ms'] ?? null) === false) {
                throw new RuntimeException('Invalid worker record.');
            }

            $ids[] = $row['id'];
            $samples[] = (float) $row['elapsed_ms'];
            $polls[] = (int) ($row['reserve_polls'] ?? 0);
            $memory[] = (int) ($row['peak_memory_bytes'] ?? 0);
        } catch (Throwable $exception) {
            $failures[] = $exception::class;
        }
    }

    $children = [];

    sort($samples, SORT_NUMERIC);
    $total = count($samples);
    $quantile = static function (float $p) use ($samples, $total): ?float {
        return $total === 0 ? null : $samples[(int) floor(($total - 1) * $p)];
    };

    $delivered = (int) $db->table($table)->where('status', 'delivered')->count();
    $unique = count(array_unique($ids));

    $summary = [
        'driver' => $db->getDriverName(),
        'workers' => $workersRequested,
        'successful_workers' => $total,
        'failed_workers' => count($failures),
        'unique_deliveries' => $unique,
        'delivered_rows' => $delivered,
        'attempts_total' => (int) $db->table($table)->sum('delivery_attempts'),
        'p50_ms' => $quantile(0.50),
        'p95_ms' => $quantile(0.95),
        'p99_ms' => $quantile(0.99),
        'synchronized_delivery_wall_ms' => round((hrtime(true) - $deliveryStarted) / 1_000_000, 3),
        'reserve_polls_total' => array_sum($polls),
        'max_worker_peak_memory_bytes' => $memory === [] ? null : max($memory),
        'php_version' => PHP_VERSION,
        'elapsed_wall_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        'failures_by_class' => array_count_values($failures),
        'p99_budget_ms' => $latencyBudget,
        'benchmark_only' => true,
    ];

    echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

    if ($total !== $workersRequested || $unique !== $workersRequested
        || $delivered !== $workersRequested || $summary['attempts_total'] !== $workersRequested) {
        throw new RuntimeException('Outbox lease contention invariants failed.');
    }
    if ($latencyBudget !== null && ($summary['p99_ms'] === null || $summary['p99_ms'] > $latencyBudget)) {
        throw new RuntimeException('Outbox lease contention exceeded the predefined p99 budget.');
    }
} finally {
    @unlink($gate);
    for ($i = 0; $i < $workersRequested; $i++) {
        @unlink($gate.'.ready.'.$i);
    }

    foreach ($children as $child) {
        if (is_resource($child['process'])) {
            proc_terminate($child['process']);
        }
        foreach ($child['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($child['process'])) {
            proc_close($child['process']);
        }
    }

    for ($i = 0; $i < $workersRequested; $i++) {
        @unlink($gate.'.ready.'.$i);
    }
    $db->getSchemaBuilder()->dropIfExists($table);
}
