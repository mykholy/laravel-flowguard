<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Domain;

enum OperationStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case RequiresReconciliation = 'requires_reconciliation';
    // Bookkeeping about externally observed outcomes, NOT local business effects.
    case ReconciledSuccess = 'reconciled_success';
    case ReconciledFailure = 'reconciled_failure';
}
