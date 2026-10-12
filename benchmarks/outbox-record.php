<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

require dirname(__DIR__).'/tests/Concurrency/bootstrap.php';

$raw = getenv('FLOWGUARD_BENCH_ITERATIONS');
$iterations = $raw === false ? 250 : filter_var($raw, FILTER_VALIDATE_INT);

if (! is_int($iterations) || $iterations < 20 || $iterations > 5000) {
    fwrite(STDERR, "FLOWGUARD_BENCH_ITERATIONS must be between 20 and 5000.\n");
    exit(1);
}

// The shared test bootstrap rejects every database except flowguard_ci.
$db = flowguardConcurrencyConnection();
$table = 'fg_bench_'.bin2hex(random_bytes(6));

$db->getSchemaBuilder()->create($table, function (Blueprint $blueprint): void {
    $blueprint->uuid('id')->primary();
    $blueprint->string('scope', 120);
    $blueprint->char('event_key_hash', 64);
    $blueprint->string('topic', 120);
    $blueprint->string('subject_ref', 128);
    $blueprint->string('status', 20);
    $blueprint->timestamps(6);
    $blueprint->unique(['scope', 'event_key_hash']);
});

try {
    $recorder = new DatabaseOutboxRecorder($db, $table);
    $samples = [];

    for ($i = 0; $i < $iterations; $i++) {
        $started = hrtime(true);

        $db->transaction(static function () use ($recorder, $i): void {
            $recorder->record('tenant.bench', 'event-'.$i, 'bench.recorded', 'subject-'.$i);
        });

        $samples[] = (hrtime(true) - $started) / 1_000_000;
    }

    sort($samples, SORT_NUMERIC);

    $p50 = $samples[(int) floor(($iterations - 1) * 0.50)];
    $p95 = $samples[(int) floor(($iterations - 1) * 0.95)];
    $p99 = $samples[(int) floor(($iterations - 1) * 0.99)];

    echo json_encode([
        'driver' => $db->getDriverName(),
        'iterations' => $iterations,
        'unit' => 'milliseconds',
        'p50' => round($p50, 3),
        'p95' => round($p95, 3),
        'p99' => round($p99, 3),
        'note' => 'Disposable CI database. Not a production capacity guarantee.',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
} finally {
    $db->getSchemaBuilder()->dropIfExists($table);
}
