<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Reconciliation;

use LogicException;
use Mykholy\Flowguard\Contracts\Reconciler;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;

/** Deliberately has no OperationStore access and cannot finalize or retry work. */
final readonly class ReconciliationInspector
{
    public function __construct(private Reconciler $reconciler) {}

    public function inspect(OperationSnapshot $operation): ReconciliationEvidence
    {
        if ($operation->status !== OperationStatus::RequiresReconciliation) {
            throw new LogicException('Only operations explicitly requiring reconciliation may be inspected.');
        }

        return $this->reconciler->inspect($operation);
    }
}
