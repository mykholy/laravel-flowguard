<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Mykholy\Flowguard\Contracts\Reconciler;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationInspector;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use PHPUnit\Framework\TestCase;

final class ReconciliationInspectorTest extends TestCase
{
    public function test_inspection_returns_provider_evidence_without_mutating_operation(): void
    {
        $snapshot = new OperationSnapshot('id', 'payments.capture', OperationStatus::RequiresReconciliation, null, 'provider_timeout');
        $evidence = new ReconciliationEvidence(ReconciliationOutcome::ConfirmedSuccess, 'payments.gateway', 'capture_found', new DateTimeImmutable('2026-10-12T05:00:00+00:00'), 'ref-456');
        $provider = $this->createMock(Reconciler::class);
        $provider->expects(self::once())->method('inspect')->with(self::identicalTo($snapshot))->willReturn($evidence);

        $inspector = new ReconciliationInspector($provider);
        self::assertSame($evidence, $inspector->inspect($snapshot));
        self::assertSame(OperationStatus::RequiresReconciliation, $snapshot->status);
    }

    public function test_only_unknown_outcomes_can_be_inspected(): void
    {
        $provider = $this->createMock(Reconciler::class);
        $provider->expects(self::never())->method('inspect');
        $inspector = new ReconciliationInspector($provider);

        $this->expectException(LogicException::class);
        $inspector->inspect(new OperationSnapshot('id', 'payments.capture', OperationStatus::Completed, [], null));
    }

    public function test_unstructured_evidence_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReconciliationEvidence(ReconciliationOutcome::Inconclusive, 'error: card 4242 4242', 'unknown', new DateTimeImmutable);
    }

    public function test_control_characters_are_rejected_from_provider_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReconciliationEvidence(ReconciliationOutcome::ConfirmedSuccess, 'gateway', 'found', new DateTimeImmutable, "reference\nsecond-line");
    }
}
