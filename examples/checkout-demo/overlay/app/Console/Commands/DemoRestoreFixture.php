<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Demo\Checkout;
use App\Demo\OrderPublisher;
use App\Demo\ProviderClient;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Psr\Log\NullLogger;
use RuntimeException;

final class DemoRestoreFixture extends Command
{
    protected $signature = 'demo:restore-fixture {phase} {manifest}';

    protected $description = 'Prepare or verify the disposable full-host native restore drill';

    private const TABLES = ['demo_tenants', 'demo_orders', 'demo_payments', 'demo_approvals',
        'flowguard_operations', 'flowguard_reconciliation_evidence', 'flowguard_resolution_decisions', 'flowguard_inbox', 'flowguard_outbox'];

    public function handle(Checkout $checkout, ProviderClient $provider): int
    {
        $phase = $this->argument('phase');
        $expected = $phase === 'prepare' ? 'flowguard_demo_ci' : 'flowguard_demo_restore_ci';
        $this->check(app()->environment('testing') && getenv('DEMO_RESTORE_CONFIRM') === 'disposable-only'
            && in_array($phase, ['prepare', 'verify'], true) && DB::connection()->getDatabaseName() === $expected,
            'Restore drills require explicitly named disposable databases and opt-in.');
        $path = $this->argument('manifest');
        $store = new DatabaseOutboxLeaseStore(DB::connection(), scope: 'tenant.1:orders');
        $dispatcher = new OutboxDispatcher($store, app(OrderPublisher::class), new NullLogger);
        if ($phase === 'prepare') {
            $this->check(DB::table('demo_orders')->count() === 0, 'Prepare requires an empty migrated host.');
            $first = $checkout->create(1, 'restore-paid', 12500, 'USD')['id'];
            $paid = $checkout->charge(1, $first)['operation_id'];
            $this->check($dispatcher->dispatchOne(), 'Delivered fixture missing.');
            $second = $checkout->create(1, 'restore-unknown', 12500, 'USD')['id'];
            config(['demo.delay_ack' => true]);
            $unknown = $checkout->charge(1, $second);
            config(['demo.delay_ack' => false]);
            $this->check($unknown['status'] === 'requires_reconciliation', 'Real transport timeout did not occur.');
            $evidence = $checkout->evidence(1, $unknown['operation_id'], 'reviewer-a');
            $approval = $checkout->requestApproval(1, $unknown['operation_id'], $evidence, 'reviewer-a');
            $lease = $store->reserve(3600);
            $this->check($lease !== null, 'Active lease fixture missing.');
            // This fixture starts after verified webhook authentication. HTTP tests cover signatures.
            $payload = ['order_id' => $second, 'amount' => 12500, 'currency' => 'USD'];
            (new DatabaseInboxRecorder(DB::connection()))->receive('tenant.1:orders', 'restore-event', $payload, 'payment.received', $second);
            $other = $checkout->create(2, 'restore-other-tenant', 500, 'EUR')['id'];
            file_put_contents($path, json_encode(['fingerprints' => $this->fingerprints(), 'first' => $first,
                'second' => $second, 'other' => $other, 'paid' => $paid, 'unknown' => $unknown['operation_id'],
                'approval' => $approval, 'leased' => $lease->id, 'payload' => $payload], JSON_THROW_ON_ERROR));
            chmod($path, 0600);
        } else {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->check($this->fingerprints() === $data['fingerprints'], 'Native restore changed persisted host or Flowguard rows.');
            $this->check($checkout->create(1, 'restore-paid', 12500, 'USD')['id'] === $data['first'], 'Restored order claim lost deduplication.');
            $this->check($checkout->charge(1, $data['first'])['duplicate'], 'Restored payment was recaptured.');
            $this->check($provider->receipt($data['paid'])['attempts'] === 1, 'Completed payment had another provider attempt.');
            $receipt = (new DatabaseInboxRecorder(DB::connection()))->receive('tenant.1:orders', 'restore-event', $data['payload'], 'payment.received', $data['second']);
            $this->check($receipt->duplicate, 'Restored inbox lost deduplication.');
            $this->check($dispatcher->dispatchOne(), 'Restored pending event did not dispatch.');
            $this->check(! $dispatcher->dispatchOne(), 'An active restored lease was stolen.');
            $this->check(DB::table('flowguard_outbox')->where('id', $data['leased'])->value('status') === 'leased', 'Active lease changed.');
            $this->check($provider->receipt($data['leased']) === null, 'Active leased event was delivered early.');
            DB::table('flowguard_outbox')->where('id', $data['leased'])->update(['lease_expires_at' => now()->subSecond()]);
            $this->check($dispatcher->dispatchOne(), 'Expired restored lease did not recover.');
            $this->check($provider->receipt($data['leased'])['attempts'] === 1, 'Recovered message identity changed.');
            $checkout->approve(1, $data['approval'], 'reviewer-b');
            $this->check($checkout->order(1, $data['second'])->status === 'paid', 'Approval did not restore business state.');
            $this->check($provider->receipt($data['unknown'])['attempts'] === 1, 'Reconciliation recaptured payment.');
            $this->check(DB::table('flowguard_resolution_decisions')->count() === 1, 'Missing restored audit linkage.');
            foreach ([['demo_tenants', 'id', 1], ['demo_orders', 'id', $data['first']],
                ['flowguard_operations', 'id', $data['unknown']]] as [$table, $key, $id]) {
                $rejected = false;
                try {
                    DB::transaction(fn () => DB::table($table)->where($key, $id)->delete());
                } catch (QueryException) {
                    $rejected = true;
                }
                $this->check($rejected, 'Restored foreign key did not protect '.$table);
            }
        }
        $this->info('Full demo host native restore '.$phase.' passed (synthetic provider).');

        return self::SUCCESS;
    }

    private function fingerprints(): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $column = $table === 'demo_payments' ? 'operation_id' : 'id';
            $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy($column)->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
