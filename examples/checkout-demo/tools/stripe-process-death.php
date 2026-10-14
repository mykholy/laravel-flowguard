<?php

declare(strict_types=1);

// Opt-in external acceptance experiment. Uses the pilot's fixed test-only payment.
// Usage: php stripe-process-death.php /absolute/demo-host /private/report.json
use App\Demo\StripeSandbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

$host = realpath($argv[1] ?? '');
$reportPath = $argv[2] ?? '';
if ($host === false || ! is_file($host.'/artisan') || ! str_starts_with($reportPath, '/')) {
    throw new RuntimeException('An absolute demo host and private report path are required.');
}
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, 'Stripe process-death experiment failed: '.get_class($exception)."\n");
    exit(1);
});

if (($argv[3] ?? '') === '--child') {
    Http::globalRequestMiddleware(function (RequestInterface $request) use ($reportPath): RequestInterface {
        if ($request->getMethod() === 'GET') {
            // The command already confirmed remotely and durably marked the outcome unknown.
            // Kill before the first observation: no journal, decision or paid bookkeeping exists.
            $payment = DB::table('demo_payments')->where('status', 'unknown')
                ->whereNotNull('encrypted_provider_reference')->orderByDesc('created_at')->firstOrFail();
            file_put_contents($reportPath.'.operation', $payment->operation_id);
            chmod($reportPath.'.operation', 0600);
            posix_kill(getmypid(), SIGKILL);
            throw new RuntimeException('SIGKILL failed.');
        }

        return $request;
    });
    exit($kernel->call('demo:stripe-pilot'));
}

$child = new Process([PHP_BINARY, __FILE__, $host, $reportPath, '--child'], $host);
$child->setTimeout(120);
try {
    $child->run();
} catch (ProcessSignaledException $exception) {
    if ($exception->getSignal() !== SIGKILL) {
        throw $exception;
    }
}
if (! $child->hasBeenSignaled() || $child->getTermSignal() !== SIGKILL) {
    throw new RuntimeException('Pilot did not terminate at the required SIGKILL boundary.');
}
$marker = $reportPath.'.operation';
$operation = trim(file_get_contents($marker));
unlink($marker);
$payment = DB::table('demo_payments')->where('operation_id', $operation)->firstOrFail();
if ($payment->status !== 'unknown'
    || DB::table('flowguard_operations')->where('id', $operation)->value('status') !== 'requires_reconciliation') {
    throw new RuntimeException('Killed worker did not leave the required unknown state.');
}
$reads = 0;
Http::globalRequestMiddleware(function (RequestInterface $request) use (&$reads): RequestInterface {
    if ($request->getUri()->getHost() !== 'api.stripe.com' || $request->getMethod() !== 'GET') {
        throw new RuntimeException('Recovery attempted an unauthorized provider write.');
    }
    $reads++;

    return $request;
});
if ($kernel->call('demo:stripe-pilot', ['--resume' => $operation]) !== 0 || $reads !== 2) {
    throw new RuntimeException('GET-only recovery failed.');
}
$intent = Crypt::decryptString($payment->encrypted_provider_reference);
$response = Http::withBasicAuth(config('demo.stripe_test_key'), '')
    ->withHeaders(['Stripe-Version' => StripeSandbox::API_VERSION])
    ->withoutRedirecting()->connectTimeout(10)->timeout(30)
    ->get('https://api.stripe.com/v1/charges', ['payment_intent' => $intent, 'limit' => 10]);
$ledger = $response->json();
$charge = $ledger['data'][0] ?? [];
if (! $response->successful() || count($ledger['data'] ?? []) !== 1 || ($ledger['has_more'] ?? true)
    || ($charge['payment_intent'] ?? null) !== $intent || ($charge['livemode'] ?? true)
    || ($charge['paid'] ?? false) !== true || ($charge['captured'] ?? false) !== true
    || ($charge['amount'] ?? null) !== 500 || ($charge['currency'] ?? null) !== 'usd'
    || DB::table('demo_payments')->where('operation_id', $operation)->value('status') !== 'paid'
    || DB::table('flowguard_operations')->where('id', $operation)->value('status') !== 'reconciled_success') {
    throw new RuntimeException('Provider ledger or recovered bookkeeping mismatch.');
}
$report = ['status' => 'passed', 'mode' => 'sandbox', 'live_mode' => false,
    'termination_signal' => SIGKILL, 'boundary' => 'after_durable_unknown_before_first_observation',
    'recovery_post_requests' => 0, 'recovery_get_requests' => 2,
    'matching_provider_charges' => 1, 'amount_minor' => 500, 'currency' => 'usd',
    'final_operation_state' => 'reconciled_success', 'final_payment_state' => 'paid',
    'full_provider_pilot_complete' => false];
file_put_contents($reportPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
chmod($reportPath, 0600);
echo "Stripe process-death experiment: passed\n";
