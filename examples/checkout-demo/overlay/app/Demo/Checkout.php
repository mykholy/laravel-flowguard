<?php

declare(strict_types=1);

namespace App\Demo;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Reconciliation\DatabaseManualResolver;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use Throwable;

final readonly class Checkout
{
    public function __construct(private Flowguard $guard, private ProviderClient $provider, private ReviewPolicy $policy) {}

    public function create(int $tenant, string $key, int $amount, string $currency): array
    {
        return DB::transaction(function () use ($tenant, $key, $amount, $currency): array {
            $acquisition = $this->guard->begin('tenant.'.$tenant.':create-order', $key, compact('amount', 'currency'));
            if (! $acquisition->acquired()) {
                abort_unless($acquisition->operation->status === OperationStatus::Completed, 409);

                return ['id' => $acquisition->operation->result['order_id'], 'duplicate' => true];
            }
            $id = (string) Str::uuid();
            DB::table('demo_orders')->insert(['id' => $id, 'tenant_id' => $tenant, 'amount' => $amount,
                'currency' => $currency, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            (new DatabaseOutboxRecorder(DB::connection()))->record('tenant.'.$tenant.':orders', $id.':created', 'order.created', $id);
            $this->guard->complete($acquisition->claim(), ['order_id' => $id]);

            return ['id' => $id, 'duplicate' => false];
        });
    }

    public function order(int $tenant, string $id): object
    {
        return DB::table('demo_orders')->where('tenant_id', $tenant)->where('id', $id)->firstOrFail();
    }

    public function charge(int $tenant, string $orderId): array
    {
        $order = $this->order($tenant, $orderId);
        $acquisition = $this->guard->begin('tenant.'.$tenant.':payments', $orderId, ['order' => $orderId, 'amount' => (int) $order->amount, 'currency' => $order->currency]);
        if (! $acquisition->acquired()) {
            abort_unless(in_array($acquisition->operation->status, [OperationStatus::Completed, OperationStatus::ReconciledSuccess], true), 409, 'Payment needs review; no replay was performed.');

            return ['operation_id' => $acquisition->operation->id, 'status' => 'paid', 'duplicate' => true];
        }
        $operation = $acquisition->operation->id;
        DB::table('demo_payments')->insert(['operation_id' => $operation, 'order_id' => $orderId, 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $this->provider->accept($operation, $tenant, 'payment.capture', $orderId, (int) $order->amount, $order->currency);
        } catch (Throwable) {
            DB::transaction(function () use ($acquisition, $operation): void {
                $this->guard->requireReconciliation($acquisition->claim(), 'provider_outcome_unknown');
                DB::table('demo_payments')->where('operation_id', $operation)->update(['status' => 'unknown', 'updated_at' => now()]);
            });

            return ['operation_id' => $operation, 'status' => 'requires_reconciliation', 'duplicate' => false];
        }
        DB::transaction(function () use ($acquisition, $operation, $orderId): void {
            $this->guard->complete($acquisition->claim(), ['order_id' => $orderId]);
            DB::table('demo_payments')->where('operation_id', $operation)->update(['status' => 'paid', 'updated_at' => now()]);
            DB::table('demo_orders')->where('id', $orderId)->update(['status' => 'paid', 'updated_at' => now()]);
        });

        return ['operation_id' => $operation, 'status' => 'paid', 'duplicate' => false];
    }

    public function evidence(int $tenant, string $operation, string $actor): string
    {
        $payment = DB::table('demo_payments')->join('demo_orders', 'demo_orders.id', '=', 'demo_payments.order_id')
            ->where('operation_id', $operation)->where('tenant_id', $tenant)->firstOrFail();
        // GET only: observing success never replays a provider-side capture.
        $receipt = $this->provider->receipt($operation);
        $confirmed = $receipt !== null && (int) $receipt['tenant'] === $tenant
            && $receipt['subject'] === $payment->order_id && $receipt['topic'] === 'payment.capture'
            && (int) $receipt['amount'] === (int) $payment->amount && $receipt['currency'] === $payment->currency;
        $entry = (new DatabaseEvidenceJournal(DB::connection(), $this->policy))->append($operation,
            new ReconciliationEvidence($confirmed ? ReconciliationOutcome::ConfirmedSuccess : ReconciliationOutcome::Inconclusive,
                'demo.sandbox', $confirmed ? 'ledger.accepted' : 'ledger.inconclusive', new DateTimeImmutable), $actor);

        return $entry->id;
    }

    public function requestApproval(int $tenant, string $operation, string $evidence, string $actor): string
    {
        $row = DB::table('flowguard_reconciliation_evidence')->where('id', $evidence)->where('operation_id', $operation)->firstOrFail();
        $stored = DB::table('flowguard_operations')->where('id', $operation)->firstOrFail();
        abort_unless($stored->scope === 'tenant.'.$tenant.':payments', 404);
        abort_unless($stored->status === OperationStatus::RequiresReconciliation->value, 409);
        $snapshot = new \Mykholy\Flowguard\Domain\OperationSnapshot($operation, $stored->scope, OperationStatus::from($stored->status), null, null);
        $this->policy->assertCanRecord($actor, $snapshot);
        $id = (string) Str::uuid();
        DB::table('demo_approvals')->insert(['id' => $id, 'tenant_id' => $tenant, 'operation_id' => $operation,
            'evidence_id' => $row->id, 'initiator' => $actor, 'resolved' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function approve(int $tenant, string $id, string $actor): void
    {
        DB::transaction(function () use ($tenant, $id, $actor): void {
            $approval = DB::table('demo_approvals')->where('id', $id)->where('tenant_id', $tenant)->lockForUpdate()->firstOrFail();
            abort_if($approval->resolved, 409);
            abort_if($approval->initiator === $actor, 403);
            DB::table('demo_approvals')->where('id', $id)->update(['approver' => $actor, 'updated_at' => now()]);
            $decision = (new DatabaseManualResolver(DB::connection(), $this->policy))->resolve($approval->operation_id, $approval->evidence_id, $approval->initiator, $actor, $id);
            $status = match ($decision->status) {
                OperationStatus::ReconciledSuccess => 'paid',
                OperationStatus::ReconciledFailure => 'failed',
                default => throw new \LogicException('Unsupported reconciliation result.'),
            };
            $payment = DB::table('demo_payments')->where('operation_id', $approval->operation_id)->firstOrFail();
            DB::table('demo_payments')->where('operation_id', $approval->operation_id)->update(['status' => $status, 'updated_at' => now()]);
            DB::table('demo_orders')->where('id', $payment->order_id)->update(['status' => $status, 'updated_at' => now()]);
            DB::table('demo_approvals')->where('id', $id)->update(['resolved' => true, 'updated_at' => now()]);
        });
    }
}
