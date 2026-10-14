<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Demo\StripeSandbox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Offline HTTP contracts: these tests are NOT evidence of an external Stripe run. */
final class StripePilotContractTest extends TestCase
{
    private array $intent = [];

    private int $confirmations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || (DB::connection()->getDriverName() === 'sqlite'
            ? basename($database) !== 'flowguard-demo-test.sqlite' : $database !== 'flowguard_demo_ci')) {
            throw new RuntimeException('Contract tests require the disposable demo test database.');
        }
        if (DB::connection()->getDriverName() === 'sqlite' && ! is_file($database)) {
            touch($database);
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['demo.stripe_test_key' => 'sk_test_OFFLINE_CONTRACT_ONLY', 'demo.stripe_pilot_confirm' => 'sandbox-only']);
        Http::preventStrayRequests();
    }

    private function fakeStripe(bool $loseResponse = false, bool $wrongTenant = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($loseResponse, $wrongTenant) {
            self::assertStringStartsWith('https://api.stripe.com/v1/payment_intents', $request->url());
            self::assertSame(['2026-09-30.endive'], $request->header('Stripe-Version'));
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/payment_intents')) {
                self::assertSame('false', $request['confirm']);
                self::assertSame(['card'], $request['allowed_payment_method_types']);
                self::assertArrayNotHasKey('payment_method_types', $request->data());
                self::assertSame(500, (int) $request['amount']);
                $this->intent = ['id' => 'pi_OFFLINECONTRACT', 'object' => 'payment_intent', 'livemode' => false,
                    'status' => 'requires_payment_method', 'amount' => 500, 'currency' => 'usd',
                    'metadata' => $request['metadata'], 'client_secret' => 'MUST_NOT_PERSIST'];
                self::assertSame('flowguard:'.$this->intent['metadata']['flowguard_operation'].':prepare', $request->header('Idempotency-Key')[0]);
            } elseif ($request->method() === 'POST') {
                self::assertSame('pm_card_visa', $request['payment_method']);
                self::assertSame('flowguard:'.$this->intent['metadata']['flowguard_operation'].':confirm', $request->header('Idempotency-Key')[0]);
                $this->confirmations++;
                $this->intent['status'] = 'succeeded';
                $this->intent['latest_charge'] = 'ch_OFFLINECONTRACT';
                if ($loseResponse) {
                    throw new ConnectionException('INTENTIONALLY_SENSITIVE_HTTP_ERROR');
                }
            } else {
                self::assertSame('GET', $request->method());
                if ($wrongTenant) {
                    $this->intent['metadata']['demo_tenant'] = '2';
                }
            }

            return Http::response($this->intent);
        });
    }

    public function test_live_key_is_rejected_before_provider_traffic_or_domain_writes(): void
    {
        Http::fake();
        config(['demo.stripe_test_key' => 'sk_live_OFFLINE_FORBIDDEN']);
        $this->artisan('demo:stripe-pilot')->assertFailed();
        Http::assertNothingSent();
        self::assertSame(0, DB::table('demo_orders')->count());
    }

    public function test_missing_opt_in_rejects_even_a_test_key(): void
    {
        Http::fake();
        config(['demo.stripe_pilot_confirm' => '']);
        $this->artisan('demo:stripe-pilot')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_offline_contract_discards_ack_and_recovers_with_one_confirm_and_encrypted_reference(): void
    {
        $this->fakeStripe();
        $this->artisan('demo:stripe-pilot')->assertSuccessful();
        self::assertSame(1, $this->confirmations);
        Http::assertSentCount(4); // create, confirm, read-only observation, read-only verification.
        $payment = DB::table('demo_payments')->sole();
        self::assertSame('paid', $payment->status);
        self::assertNotSame($this->intent['id'], $payment->encrypted_provider_reference);
        self::assertSame($this->intent['id'], Crypt::decryptString($payment->encrypted_provider_reference));
        self::assertSame('reconciled_success', DB::table('flowguard_operations')->where('id', $payment->operation_id)->value('status'));
        self::assertSame(1, DB::table('flowguard_resolution_decisions')->count());
        $evidence = json_encode(DB::table('flowguard_reconciliation_evidence')->get(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('pi_OFFLINECONTRACT', $evidence);
        self::assertStringNotContainsString('MUST_NOT_PERSIST', $evidence);
        self::assertStringNotContainsString(config('demo.stripe_test_key'), $evidence);
    }

    public function test_unknown_transport_outcome_can_resume_with_get_only_and_no_reconfirmation(): void
    {
        $this->fakeStripe(loseResponse: true);
        $this->artisan('demo:stripe-pilot')->assertFailed();
        $payment = DB::table('demo_payments')->sole();
        self::assertSame('unknown', $payment->status);
        self::assertSame(0, DB::table('flowguard_resolution_decisions')->count());
        $this->fakeStripe();
        $this->artisan('demo:stripe-pilot', ['--resume' => $payment->operation_id])->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
        self::assertSame(1, $this->confirmations);
        self::assertSame('paid', DB::table('demo_payments')->sole()->status);
    }

    public function test_mismatched_provider_tenant_cannot_settle_an_order(): void
    {
        $this->fakeStripe(wrongTenant: true);
        $this->artisan('demo:stripe-pilot')->assertFailed();
        self::assertSame(1, $this->confirmations);
        self::assertSame('unknown', DB::table('demo_payments')->sole()->status);
        self::assertSame('pending', DB::table('demo_orders')->sole()->status);
        self::assertSame(0, DB::table('flowguard_resolution_decisions')->count());
    }

    public function test_transport_details_and_credentials_do_not_appear_in_failure_report(): void
    {
        $this->fakeStripe(loseResponse: true);
        $path = tempnam(sys_get_temp_dir(), 'stripe-contract-report-');
        try {
            $this->artisan('demo:stripe-pilot', ['--report' => $path])->assertFailed();
            $report = file_get_contents($path);
            self::assertStringContainsString('stripe_transport_failed', $report);
            foreach (['INTENTIONALLY_SENSITIVE_HTTP_ERROR', 'MUST_NOT_PERSIST', config('demo.stripe_test_key'), 'pi_OFFLINECONTRACT'] as $secret) {
                self::assertStringNotContainsString($secret, $report);
            }
            self::assertFalse(json_decode($report, true, 512, JSON_THROW_ON_ERROR)['external_provider_verified']);
        } finally {
            unlink($path);
        }
    }

    public function test_live_mode_response_is_rejected_even_with_test_credentials(): void
    {
        Http::fake(['*' => Http::response(['object' => 'payment_intent', 'id' => 'pi_INVALIDLIVE', 'livemode' => true])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stripe_test_intent_required');
        app(StripeSandbox::class)->create('offline', 'offline');
    }
}
