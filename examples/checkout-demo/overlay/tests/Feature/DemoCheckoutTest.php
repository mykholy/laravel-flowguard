<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Demo\Checkout;
use App\Demo\OrderPublisher;
use App\Demo\ProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\LeaseLost;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DemoCheckoutTest extends TestCase
{
    private ?Process $provider = null;

    private ?string $state = null;

    private string $providerUrl;

    private string $providerKey;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        $driver = DB::connection()->getDriverName();
        if (! app()->environment('testing') || ($driver === 'sqlite'
            ? basename($database) !== 'flowguard-demo-test.sqlite'
            : $database !== 'flowguard_demo_ci')) {
            throw new RuntimeException('Demo tests reset only the explicitly disposable demo test database.');
        }
        if ($driver === 'sqlite' && ! is_file($database)) {
            touch($database);
        }
        // No enclosing test transaction: child PHP workers need committed visibility.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->providerUrl = 'http://'.$address;
        $this->state = tempnam(sys_get_temp_dir(), 'flowguard-demo-provider-');
        $this->providerKey = bin2hex(random_bytes(24));
        $this->provider = new Process([PHP_BINARY, '-S', $address, base_path('demo-sandbox/router.php')], base_path(),
            ['DEMO_PROVIDER_KEY' => $this->providerKey, 'DEMO_PROVIDER_STATE' => $this->state]);
        $this->provider->disableOutput();
        $this->provider->start();
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://'.$address, timeout: 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        self::assertTrue($ready, 'Independent sandbox did not start.');
        config(['demo.provider_url' => $this->providerUrl, 'demo.provider_key' => $this->providerKey]);
    }

    protected function tearDown(): void
    {
        $this->provider?->stop(1);
        if ($this->state !== null) {
            @unlink($this->state);
        }
        parent::tearDown();
    }

    private function api(string $method, string $path, array $data = [], string $actor = 'merchant-a', array $headers = [])
    {
        return $this->json($method, '/api/demo'.$path, $data, array_merge([
            'Authorization' => 'Bearer '.config('demo.principals.'.$actor.'.token'),
            'Idempotency-Key' => 'order-default',
        ], $headers));
    }

    private function order(string $key = 'order-default', string $actor = 'merchant-a'): string
    {
        return $this->api('POST', '/orders', ['amount' => 12500, 'currency' => 'USD'], $actor, ['Idempotency-Key' => $key])
            ->assertCreated()->json('id');
    }

    private function dispatcher(): OutboxDispatcher
    {
        return new OutboxDispatcher(new DatabaseOutboxLeaseStore(DB::connection(), scope: 'tenant.1:orders'),
            app(OrderPublisher::class), new NullLogger, retryAfterSeconds: 0);
    }

    private function worker(array $arguments): int
    {
        $worker = new Process(array_merge([PHP_BINARY, base_path('artisan')], $arguments), base_path(), [
            'APP_ENV' => 'testing', 'DEMO_PROVIDER_URL' => $this->providerUrl, 'DEMO_PROVIDER_KEY' => $this->providerKey,
            'DB_CONNECTION' => DB::connection()->getDriverName(), 'DB_DATABASE' => DB::connection()->getDatabaseName(),
        ]);
        $worker->setTimeout(20);
        $worker->run();

        return $worker->getExitCode();
    }

    public function test_order_creation_is_atomic_and_idempotent_and_conflicts_are_explicit(): void
    {
        $id = $this->order();
        $this->api('POST', '/orders', ['amount' => 12500, 'currency' => 'USD'])->assertOk()->assertJson(['id' => $id, 'duplicate' => true]);
        $this->api('POST', '/orders', ['amount' => 13000, 'currency' => 'USD'])->assertConflict();
        self::assertSame(1, DB::table('demo_orders')->count());
        self::assertSame(1, DB::table('flowguard_operations')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->count());
        $other = $this->order(actor: 'merchant-b');
        self::assertNotSame($id, $other);
    }

    public function test_tenants_are_server_derived_and_other_tenants_cannot_read_or_charge_orders(): void
    {
        $this->postJson('/api/demo/orders', ['amount' => 1, 'currency' => 'USD'])->assertUnauthorized();
        $id = $this->api('POST', '/orders', ['amount' => 12500, 'currency' => 'USD', 'tenant_id' => 2], headers: ['X-Tenant-ID' => '2'])->assertCreated()->json('id');
        self::assertSame(1, (int) DB::table('demo_orders')->where('id', $id)->value('tenant_id'));
        $this->api('GET', '/orders/'.$id, actor: 'merchant-b')->assertNotFound();
        $this->api('POST', '/orders/'.$id.'/charge', actor: 'merchant-b')->assertNotFound();
        $this->api('POST', '/orders/'.$id.'/charge', actor: 'reviewer-a')->assertForbidden();
    }

    public function test_business_rollback_discards_order_claim_and_outbox_together(): void
    {
        try {
            DB::transaction(function (): void {
                app(Checkout::class)->create(1, 'rolled-back', 12500, 'USD');
                throw new RuntimeException('intentional_demo_rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('intentional_demo_rollback', $exception->getMessage());
        }
        self::assertSame(0, DB::table('demo_orders')->count());
        self::assertSame(0, DB::table('flowguard_operations')->count());
        self::assertSame(0, DB::table('flowguard_outbox')->count());
    }

    public function test_successful_capture_and_duplicate_request_create_one_provider_attempt(): void
    {
        $id = $this->order();
        $operation = $this->api('POST', '/orders/'.$id.'/charge')->assertOk()->json('operation_id');
        $this->api('POST', '/orders/'.$id.'/charge')->assertOk()->assertJson(['duplicate' => true, 'operation_id' => $operation]);
        self::assertSame(1, app(ProviderClient::class)->receipt($operation)['attempts']);
        self::assertSame('paid', DB::table('demo_orders')->where('id', $id)->value('status'));
    }

    public function test_real_http_timeout_requires_independent_approval_and_never_recaptures(): void
    {
        $id = $this->order();
        config(['demo.delay_ack' => true]);
        $operation = $this->api('POST', '/orders/'.$id.'/charge')->assertStatus(202)->assertJson(['status' => 'requires_reconciliation'])->json('operation_id');
        config(['demo.delay_ack' => false]);
        $this->api('POST', '/orders/'.$id.'/charge')->assertConflict();
        $this->api('POST', '/operations/'.$operation.'/evidence')->assertForbidden();
        $evidence = $this->api('POST', '/operations/'.$operation.'/evidence', actor: 'reviewer-a')->assertCreated()->json('id');
        $approval = $this->api('POST', '/operations/'.$operation.'/approvals', ['evidence_id' => $evidence], 'reviewer-a')->assertCreated()->json('id');
        $this->api('POST', '/approvals/'.$approval.'/approve', ['approver' => 'reviewer-b'], 'reviewer-a')->assertForbidden();
        $this->api('POST', '/approvals/'.$approval.'/approve')->assertForbidden();
        $this->api('POST', '/approvals/'.$approval.'/approve', actor: 'reviewer-b')->assertOk();
        $this->api('POST', '/approvals/'.$approval.'/approve', actor: 'reviewer-b')->assertConflict();
        $this->api('POST', '/orders/'.$id.'/charge')->assertOk()->assertJson(['duplicate' => true]);
        self::assertSame(1, app(ProviderClient::class)->receipt($operation)['attempts']);
        self::assertSame('paid', DB::table('demo_orders')->where('id', $id)->value('status'));
        self::assertSame('reconciled_success', DB::table('flowguard_operations')->where('id', $operation)->value('status'));
        self::assertSame(1, DB::table('flowguard_resolution_decisions')->count());
    }

    public function test_expired_evidence_cannot_settle_the_order_or_recapture_payment(): void
    {
        $id = $this->order();
        config(['demo.delay_ack' => true]);
        $operation = $this->api('POST', '/orders/'.$id.'/charge')->assertStatus(202)->json('operation_id');
        config(['demo.delay_ack' => false]);
        $evidence = $this->api('POST', '/operations/'.$operation.'/evidence', actor: 'reviewer-a')->assertCreated()->json('id');
        $approval = $this->api('POST', '/operations/'.$operation.'/approvals', ['evidence_id' => $evidence], 'reviewer-a')->assertCreated()->json('id');
        DB::table('flowguard_reconciliation_evidence')->where('id', $evidence)->update(['observed_at' => now()->subHour()]);
        $this->api('POST', '/approvals/'.$approval.'/approve', actor: 'reviewer-b')->assertConflict();
        self::assertSame(0, DB::table('flowguard_resolution_decisions')->count());
        self::assertSame('pending', DB::table('demo_orders')->where('id', $id)->value('status'));
        self::assertNull(DB::table('demo_approvals')->where('id', $approval)->value('approver'));
        self::assertSame(1, app(ProviderClient::class)->receipt($operation)['attempts']);
    }

    private function webhook(array $payload, string $tenant = '1', ?int $timestamp = null, bool $valid = true)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $tenant.'.'.$timestamp.'.'.$body, config('demo.webhook_secret'));

        return $this->call('POST', '/api/demo/webhooks/'.$tenant, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DEMO_TIMESTAMP' => (string) $timestamp, 'HTTP_X_DEMO_SIGNATURE' => $valid ? $signature : 'forged',
        ], $body);
    }

    public function test_webhook_signature_replay_window_tenant_binding_and_deduplication(): void
    {
        $id = $this->order();
        $payload = ['event_id' => 'evt-demo-1', 'order_id' => $id, 'amount' => 12500, 'currency' => 'USD'];
        $this->webhook($payload, valid: false)->assertForbidden();
        $this->webhook($payload, timestamp: time() - 301)->assertForbidden();
        $this->webhook($payload, tenant: '2')->assertNotFound();
        self::assertSame(0, DB::table('flowguard_inbox')->count());
        $this->webhook($payload)->assertOk()->assertJson(['duplicate' => false]);
        $this->webhook($payload)->assertOk()->assertJson(['duplicate' => true]);
        self::assertSame(1, DB::table('flowguard_inbox')->count());
        self::assertSame(2, DB::table('flowguard_outbox')->count());
    }

    public function test_outbox_timeout_retries_one_remote_effect_with_the_same_message_identity(): void
    {
        $this->order();
        config(['demo.delay_ack' => true]);
        try {
            $this->dispatcher()->dispatchOne();
            self::fail('Expected an actual HTTP timeout after the sandbox committed.');
        } catch (ConnectionException) {
            // Only the transport failed; the independent ledger proves acceptance.
        }
        $message = DB::table('flowguard_outbox')->sole();
        self::assertSame('pending', $message->status);
        self::assertSame(1, app(ProviderClient::class)->receipt($message->id)['attempts']);
        config(['demo.delay_ack' => false]);
        self::assertTrue($this->dispatcher()->dispatchOne());
        self::assertSame(2, app(ProviderClient::class)->receipt($message->id)['attempts']);
        self::assertSame('delivered', DB::table('flowguard_outbox')->where('id', $message->id)->value('status'));
    }

    public function test_independent_worker_death_after_remote_commit_recovers_without_duplicate_effect(): void
    {
        $this->order();
        self::assertSame(73, $this->worker(['demo:crash-dispatch', '1']));
        $message = DB::table('flowguard_outbox')->sole();
        self::assertSame('leased', $message->status);
        self::assertSame(1, app(ProviderClient::class)->receipt($message->id)['attempts']);
        DB::table('flowguard_outbox')->where('id', $message->id)->update(['lease_expires_at' => now()->subSecond()]);
        self::assertTrue($this->dispatcher()->dispatchOne());
        self::assertSame(2, app(ProviderClient::class)->receipt($message->id)['attempts']);
        self::assertSame(2, (int) DB::table('flowguard_outbox')->where('id', $message->id)->value('delivery_attempts'));
    }

    public function test_process_death_with_a_processing_payment_claim_is_fail_closed(): void
    {
        $id = $this->order();
        self::assertSame(74, $this->worker(['demo:crash-operation', $id]));
        $operation = DB::table('demo_payments')->where('order_id', $id)->value('operation_id');
        $this->api('POST', '/orders/'.$id.'/charge')->assertConflict();
        self::assertNull(app(ProviderClient::class)->receipt($operation));
        self::assertSame('processing', DB::table('flowguard_operations')->where('id', $operation)->value('status'));
    }

    public function test_expired_lease_owner_cannot_acknowledge_a_new_workers_delivery(): void
    {
        $this->order();
        $store = new DatabaseOutboxLeaseStore(DB::connection(), scope: 'tenant.1:orders');
        $old = $store->reserve();
        self::assertNotNull($old);
        DB::table('flowguard_outbox')->where('id', $old->id)->update(['lease_expires_at' => now()->subSecond()]);
        self::assertTrue($this->dispatcher()->dispatchOne());
        $this->expectException(LeaseLost::class);
        $store->acknowledge($old);
    }

    public function test_provider_rejects_mutating_an_accepted_idempotency_identity(): void
    {
        $id = $this->order();
        $operation = $this->api('POST', '/orders/'.$id.'/charge')->assertOk()->json('operation_id');
        $this->expectException(RequestException::class);
        app(ProviderClient::class)->accept($operation, 1, 'payment.capture', $id, 13000, 'USD');
    }
}
