<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Reconciliation\EvidenceEntry;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;

interface EvidenceJournal
{
    public function append(string $operationId, ReconciliationEvidence $evidence, string $actorReference): EvidenceEntry;
}
