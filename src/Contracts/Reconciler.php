<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;

/** Read-only provider lookup. Implementations MUST NOT create/capture/refund/replay. */
interface Reconciler
{
    public function inspect(OperationSnapshot $operation): ReconciliationEvidence;
}
