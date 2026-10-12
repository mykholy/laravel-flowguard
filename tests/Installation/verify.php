<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException('Installation smoke test can run only in testing environment.');
}

$guard = $app->make(Flowguard::class);
$key = 'clean-install-'.bin2hex(random_bytes(8));
$first = $guard->begin('install.smoke', $key, ['version' => 1]);

if (! $first->acquired()) {
    throw new RuntimeException('First operation did not acquire its claim.');
}

$duplicate = $guard->begin('install.smoke', $key, ['version' => 1]);
if ($duplicate->acquired() || $duplicate->operation->id !== $first->operation->id) {
    throw new RuntimeException('Duplicate request acquired a claim.');
}

$completed = $guard->complete($first->claim(), ['ok' => true]);
if ($completed->status !== OperationStatus::Completed) {
    throw new RuntimeException('Operation was not marked completed.');
}

$id = 'outbox-install-'.bin2hex(random_bytes(8));
$recorded = DB::transaction(static fn () => (new DatabaseOutboxRecorder(DB::connection()))
    ->record('tenant.install', $id, 'install.verified', 'install-'.$id));

if (! DB::table('flowguard_outbox')->where('id', $recorded->id)->exists()) {
    throw new RuntimeException('Transactional outbox was not persisted.');
}

echo "Fresh Laravel installation: package discovery, migration, idempotency and outbox verified.\n";
