<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use PHPUnit\Framework\TestCase;

final class EvidenceReferencePrivacyTest extends TestCase
{
    private function evidence(?string $reference): ReconciliationEvidence
    {
        return new ReconciliationEvidence(
            outcome: ReconciliationOutcome::ConfirmedSuccess,
            source: 'gateway.status',
            evidenceCode: 'capture_found',
            observedAt: new DateTimeImmutable('2026-10-14T00:00:00+00:00'),
            providerReference: $reference,
        );
    }

    public function test_raw_provider_reference_is_not_retained_in_evidence_object(): void
    {
        $reference = 'customer-private-reference-849202';
        $evidence = $this->evidence($reference);

        self::assertTrue($evidence->providerReferencePresent);
        self::assertFalse(property_exists($evidence, 'providerReference'));
        self::assertNotContains($reference, array_values(get_object_vars($evidence)));

        foreach ([
            json_encode($evidence, JSON_THROW_ON_ERROR),
            serialize($evidence),
            print_r($evidence, true),
            var_export($evidence, true),
        ] as $representation) {
            self::assertStringNotContainsString($reference, $representation);
        }

        ob_start();
        var_dump($evidence);
        $dump = ob_get_clean();

        self::assertIsString($dump);
        self::assertStringNotContainsString($reference, $dump);
    }

    public function test_missing_reference_remains_a_safe_absence_marker(): void
    {
        $evidence = $this->evidence(null);

        self::assertFalse($evidence->providerReferencePresent);
        self::assertStringContainsString(
            '"providerReferencePresent":false',
            json_encode($evidence, JSON_THROW_ON_ERROR),
        );
    }

    public function test_invalid_reference_is_rejected_before_marker_is_created(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evidence("private-reference\nunsafe");
    }
}
