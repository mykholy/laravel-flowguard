<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Reconciliation;

enum ReconciliationOutcome: string
{
    case ConfirmedSuccess = 'confirmed_success';
    case ConfirmedFailure = 'confirmed_failure';
    case Inconclusive = 'inconclusive';
}
