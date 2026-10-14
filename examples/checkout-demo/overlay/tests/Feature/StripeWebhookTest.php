<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Demo\Checkout;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mykholy\Flowguard\Flowguard;
use RuntimeException;
use Tests\TestCase;

/** Signed offline fixtures; not evidence of delivery from Stripe. */
final class StripeWebhookTest extends TestCase
{
    private const SECRET = 'whsec_OFFLINE_WEBHOOK_CONTRACT_ONLY';

    private array $event;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || (DB::connection()->getDriverName() === 'sqlite'
            ? basename($database) !== 'flowguard-demo-test.sqlite' : $database !== 'flowguard_demo_ci')) {
            throw new RuntimeException('Webhook tests require the disposable demo test database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Http::preventStrayRequests();
        config(['demo.stripe_webhook_secret' => self::SECRET]);
        $order = app(Checkout::class)->create(1, 'stripe-webhook-fixture', 500, 'USD')['id'];
        $guard = app(Flowguard::class);
        $claim = $guard->begin('tenant.1:payments', $order, ['order' => $order, 'amount' => 500, 'currency' => 'USD']);
        DB::table('demo_payments')->insert(['operation_id' => $claim->operation->id, 'order_id' => $order,
            'status' => 'unknown', 'encrypted_provider_reference' => Crypt::encryptString('pi_OFFLINEWEBHOOK'),
            'created_at' => now(), 'updated_at' => now()]);
        $guard->requireReconciliation($claim->claim(), 'webhook_fixture_unknown');
        $this->event = ['id' => 'evt_OFFLINEWEBHOOK', 'object' => 'event', 'livemode' => false,
            'type' => 'payment_intent.succeeded', 'data' => ['object' => [
                'id' => 'pi_OFFLINEWEBHOOK', 'object' => 'payment_intent', 'livemode' => false,
                'status' => 'succeeded', 'amount' => 500, 'currency' => 'usd',
                'client_secret' => 'MUST_NOT_PERSIST', 'metadata' => ['demo_tenant' => '1',
                    'flowguard_operation' => $claim->operation->id, 'demo_order' => $order]]]];
    }

    private function deliver(array $event, ?int $timestamp = null, ?string $signature = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call('POST', '/api/demo/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
    }

    public function test_verified_duplicate_records_one_signal_and_never_settles_the_payment(): void
    {
        $before = DB::table('flowguard_outbox')->count();
        $first = $this->deliver($this->event)->assertOk()->assertJson(['duplicate' => false])->json('id');
        $this->deliver($this->event)->assertOk()->assertJson(['id' => $first, 'duplicate' => true]);
        self::assertSame(1, DB::table('flowguard_inbox')->count());
        self::assertSame($before + 1, DB::table('flowguard_outbox')->count());
        self::assertSame('unknown', DB::table('demo_payments')->value('status'));
        self::assertSame('requires_reconciliation', DB::table('flowguard_operations')->where('scope', 'tenant.1:payments')->value('status'));
        self::assertSame(0, DB::table('flowguard_reconciliation_evidence')->count());
        $stored = json_encode(DB::table('flowguard_inbox')->first(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('pi_OFFLINEWEBHOOK', $stored);
        self::assertStringNotContainsString('MUST_NOT_PERSIST', $stored);
        Http::assertNothingSent();
    }

    public function test_forged_expired_future_and_mutated_signatures_are_rejected(): void
    {
        $this->deliver($this->event, signature: 't='.time().',v1='.str_repeat('0', 64))->assertForbidden();
        $this->deliver($this->event, time() - 301)->assertForbidden();
        $this->deliver($this->event, time() + 301)->assertForbidden();
        $body = json_encode($this->event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
        $changed = $this->event;
        $changed['data']['object']['amount'] = 501;
        $this->deliver($changed, signature: $signature)->assertForbidden();
        self::assertSame(0, DB::table('flowguard_inbox')->count());
    }

    public function test_signed_event_cannot_choose_tenant_account_mode_or_payment_binding(): void
    {
        foreach ([['livemode', true], ['account', 'acct_OTHER']] as [$key, $value]) {
            $event = $this->event;
            $event[$key] = $value;
            $this->deliver($event)->assertStatus(422);
        }
        foreach ([['livemode', true, 422], ['id', 'pi_OTHER', 409], ['amount', 501, 409],
            ['currency', 'eur', 409], ['status', 'processing', 409]] as [$key, $value, $status]) {
            $event = $this->event;
            $event['data']['object'][$key] = $value;
            $this->deliver($event)->assertStatus($status);
        }
        $event = $this->event;
        $event['data']['object']['metadata']['demo_tenant'] = '2';
        $this->deliver($event)->assertStatus(422);
        $event = $this->event;
        $event['data']['object']['metadata']['demo_order'] = '00000000-0000-4000-8000-000000000000';
        $this->deliver($event)->assertNotFound();
        self::assertSame(0, DB::table('flowguard_inbox')->count());
    }

    public function test_same_event_identity_with_changed_signed_payload_conflicts(): void
    {
        $this->deliver($this->event)->assertOk();
        $event = $this->event;
        $event['data']['object']['metadata']['additional'] = 'changed';
        $this->deliver($event)->assertStatus(409);
        self::assertSame(1, DB::table('flowguard_inbox')->count());
    }

    public function test_multiple_v1_signatures_are_supported_and_missing_secret_fails_closed(): void
    {
        $body = json_encode($this->event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $this->deliver($this->event, signature: 't='.$timestamp.',v1='.str_repeat('0', 64)
            .',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET))->assertOk();
        config(['demo.stripe_webhook_secret' => '']);
        $this->deliver($this->event)->assertStatus(503);
    }

    public function test_payment_failure_is_a_durable_observation_without_automatic_retry(): void
    {
        $event = $this->event;
        $event['type'] = 'payment_intent.payment_failed';
        $event['data']['object']['status'] = 'requires_payment_method';
        $this->deliver($event)->assertOk();
        self::assertSame('unknown', DB::table('demo_payments')->value('status'));
        Http::assertNothingSent();
    }

    public function test_malformed_and_oversized_requests_do_not_create_receipts(): void
    {
        foreach (['{', str_repeat(' ', 65537)] as $body) {
            $timestamp = time();
            $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
            $this->call('POST', '/api/demo/stripe/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body)
                ->assertStatus(strlen($body) > 65536 ? 413 : 403);
        }
        $event = $this->event;
        $event['data']['object']['metadata'] = 'invalid';
        $this->deliver($event)->assertStatus(422);
        self::assertSame(0, DB::table('flowguard_inbox')->count());
    }
}
