<?php

declare(strict_types=1);

namespace App\Demo;

use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Contracts\ResolutionAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;

final class ReviewPolicy implements EvidenceAuthorization, ResolutionAuthorization
{
    public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
    {
        $principal = config('demo.principals.'.$actorReference);
        $payment = DB::table('demo_payments')->join('demo_orders', 'demo_orders.id', '=', 'demo_payments.order_id')
            ->where('operation_id', $operation->id)->first();
        abort_unless($payment !== null && is_array($principal) && $principal['token'] !== ''
            && $principal['role'] === 'reviewer' && (int) $payment->tenant_id === $principal['tenant']
            && $operation->scope === 'tenant.'.$principal['tenant'].':payments', 403);
    }

    public function assertApproved(string $initiatorReference, string $approverReference, string $approvalReference, OperationSnapshot $operation, EvidenceEntry $evidence): void
    {
        $this->assertCanRecord($initiatorReference, $operation);
        $this->assertCanRecord($approverReference, $operation);
        $approval = DB::table('demo_approvals')->where('id', $approvalReference)->first();
        abort_unless($approval !== null && ! $approval->resolved
            && $initiatorReference !== $approverReference && $approval->initiator === $initiatorReference
            && $approval->approver === $approverReference && $approval->operation_id === $operation->id
            && $approval->evidence_id === $evidence->id
            && (int) $approval->tenant_id === config('demo.principals.'.$approverReference.'.tenant'), 403);
    }
}
