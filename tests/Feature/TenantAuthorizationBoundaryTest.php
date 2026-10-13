<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Contracts\ResolutionAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Reconciliation\DatabaseManualResolver;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use RuntimeException;

/**
 * Representative host-auth policy tests. Identity and tenant membership are
 * fixed test fixtures, not credentials or policies shipped by Flowguard.
 */
final class TenantAuthorizationBoundaryTest extends TestCase
{
    /** @var array<string, string> */
    private array $memberships = [
        'tenant1.reviewer' => 'tenant.1',
        'tenant1.approver' => 'tenant.1',
        'tenant2.reviewer' => 'tenant.2',
        'tenant2.approver' => 'tenant.2',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('flowguard_test_approvals', function (Blueprint $table): void {
            $table->string('reference', 100)->primary();
            $table->string('tenant', 50);
            $table->uuid('operation_id');
            $table->uuid('evidence_id');
            $table->string('outcome', 32);
            $table->string('initiator', 120);
            $table->string('approver', 120);
            $table->boolean('approved');
        });
    }

    private function unknown(string $tenant): string
    {
        $guard = $this->app->make(Flowguard::class);
        $acquired = $guard->begin($tenant.':payments', 'capture-'.bin2hex(random_bytes(8)), ['amount_minor' => 250]);
        $guard->requireReconciliation($acquired->claim(), 'provider_timeout');

        return $acquired->operation->id;
    }

    private function journal(): DatabaseEvidenceJournal
    {
        $memberships = $this->memberships;
        $policy = new class($memberships) implements EvidenceAuthorization
        {
            /** @param array<string, string> $memberships */
            public function __construct(private array $memberships) {}

            public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
            {
                $tenant = $this->memberships[$actorReference] ?? null;

                if ($tenant === null || $operation->scope !== $tenant.':payments') {
                    throw new RuntimeException('Evidence write denied.');
                }
            }
        };

        return new DatabaseEvidenceJournal(DB::connection(), $policy);
    }

    private function evidence(string $operationId, string $actor): string
    {
        return $this->journal()->append(
            $operationId,
            new ReconciliationEvidence(
                ReconciliationOutcome::ConfirmedSuccess,
                'gateway.status',
                'confirmed_capture',
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ),
            $actor,
        )->id;
    }

    private function resolver(): DatabaseManualResolver
    {
        $memberships = $this->memberships;
        $policy = new class($memberships) implements ResolutionAuthorization
        {
            /** @param array<string, string> $memberships */
            public function __construct(private array $memberships) {}

            public function assertApproved(
                string $initiatorReference,
                string $approverReference,
                string $approvalReference,
                OperationSnapshot $operation,
                EvidenceEntry $evidence,
            ): void {
                $tenant = $this->memberships[$initiatorReference] ?? null;

                if ($tenant === null
                    || $tenant !== ($this->memberships[$approverReference] ?? null)
                    || $operation->scope !== $tenant.':payments'
                    || $operation->id !== $evidence->operationId
                ) {
                    throw new RuntimeException('Tenant or actor mismatch.');
                }

                $valid = DB::table('flowguard_test_approvals')
                    ->where('reference', $approvalReference)
                    ->where('tenant', $tenant)
                    ->where('operation_id', $operation->id)
                    ->where('evidence_id', $evidence->id)
                    ->where('outcome', $evidence->outcome->value)
                    ->where('initiator', $initiatorReference)
                    ->where('approver', $approverReference)
                    ->where('approved', true)
                    ->exists();

                if (! $valid) {
                    throw new RuntimeException('Durable approval not found.');
                }
            }
        };

        return new DatabaseManualResolver(DB::connection(), $policy);
    }

    public function test_cross_tenant_evidence_write_is_denied_without_side_effect(): void
    {
        $operation = $this->unknown('tenant.1');

        try {
            $this->evidence($operation, 'tenant2.reviewer');
            self::fail('Cross-tenant actor must not attach evidence.');
        } catch (RuntimeException $exception) {
            self::assertSame('Evidence write denied.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('flowguard_reconciliation_evidence')->count());
        self::assertSame('requires_reconciliation', DB::table('flowguard_operations')->value('status'));
    }

    public function test_approval_for_another_tenant_cannot_resolve_an_operation(): void
    {
        $operation = $this->unknown('tenant.1');
        $evidence = $this->evidence($operation, 'tenant1.reviewer');

        DB::table('flowguard_test_approvals')->insert([
            'reference' => 'approval.tenant2',
            'tenant' => 'tenant.2',
            'operation_id' => $operation,
            'evidence_id' => $evidence,
            'outcome' => ReconciliationOutcome::ConfirmedSuccess->value,
            'initiator' => 'tenant1.reviewer',
            'approver' => 'tenant1.approver',
            'approved' => true,
        ]);

        try {
            $this->resolver()->resolve(
                $operation, $evidence, 'tenant1.reviewer', 'tenant1.approver', 'approval.tenant2',
            );
            self::fail('Cross-tenant approval must not finalize an operation.');
        } catch (RuntimeException $exception) {
            self::assertSame('Durable approval not found.', $exception->getMessage());
        }

        self::assertSame('requires_reconciliation', DB::table('flowguard_operations')->value('status'));
        self::assertSame(0, DB::table('flowguard_resolution_decisions')->count());
    }

    public function test_approved_same_tenant_actors_can_resolve_with_atomic_audit(): void
    {
        $operation = $this->unknown('tenant.1');
        $evidence = $this->evidence($operation, 'tenant1.reviewer');

        DB::table('flowguard_test_approvals')->insert([
            'reference' => 'approval.tenant1',
            'tenant' => 'tenant.1',
            'operation_id' => $operation,
            'evidence_id' => $evidence,
            'outcome' => ReconciliationOutcome::ConfirmedSuccess->value,
            'initiator' => 'tenant1.reviewer',
            'approver' => 'tenant1.approver',
            'approved' => true,
        ]);

        $decision = $this->resolver()->resolve(
            $operation, $evidence, 'tenant1.reviewer', 'tenant1.approver', 'approval.tenant1',
        );

        self::assertSame(OperationStatus::ReconciledSuccess, $decision->status);
        self::assertSame(1, DB::table('flowguard_resolution_decisions')->count());
    }
}
