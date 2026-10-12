<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Reconciliation;

use DateTimeImmutable;
use InvalidArgumentException;

/** A provider observation; not permission to replay or finalize an operation. */
final readonly class ReconciliationEvidence
{
    public function __construct(
        public ReconciliationOutcome $outcome,
        public string $source,
        public string $evidenceCode,
        public DateTimeImmutable $observedAt,
        public ?string $providerReference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/D', $source) !== 1) {
            throw new InvalidArgumentException('Evidence source must be a bounded, non-sensitive machine-readable identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/D', $evidenceCode) !== 1) {
            throw new InvalidArgumentException('Evidence code must be a bounded, non-sensitive machine-readable identifier.');
        }

        if ($providerReference !== null && ($providerReference === '' || strlen($providerReference) > 128 || preg_match('/[\x00-\x1f\x7f]/', $providerReference) === 1)) {
            throw new InvalidArgumentException('Provider reference must be a non-empty printable identifier of at most 128 bytes.');
        }
    }
}
