<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Concurrency;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/bootstrap.php';

/** Exercises evidence persistence on disposable MySQL 8 / PostgreSQL 16. */
final class EvidenceJournalDatabaseTest extends TestCase
{
    private Connection $db;

    private string $operations;

    private string $evidence;

    private string $operationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \flowguardConcurrencyConnection();
        $suffix = bin2hex(random_bytes(6));
        $this->operations = 'fg_ev_ops_'.$suffix;
        $this->evidence = 'fg_ev_log_'.$suffix;
        $this->operationId = '11111111-1111-4111-8111-'.$suffix;

        $this->db->getSchemaBuilder()->create($this->operations, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->string('status', 32);
            $table->string('reconciliation_reason', 120)->nullable();
        });
        $operations = $this->operations;
        $this->db->getSchemaBuilder()->create($this->evidence, function (Blueprint $table) use ($operations): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->string('actor_reference', 120);
            $table->string('outcome', 32);
            $table->string('source', 120);
            $table->string('evidence_code', 120);
            $table->dateTime('observed_at', 6);
            $table->boolean('provider_reference_present');
            $table->timestamps(6);
            $table->foreign('operation_id')->references('id')->on($operations)->restrictOnDelete();
        });
        $this->db->table($this->operations)->insert([
            'id' => $this->operationId,
            'scope' => 'payments.capture',
            'status' => 'requires_reconciliation',
            'reconciliation_reason' => 'provider_timeout',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->getSchemaBuilder()->dropIfExists($this->evidence);
            $this->db->getSchemaBuilder()->dropIfExists($this->operations);
        } finally {
            parent::tearDown();
        }
    }

    private function journal(bool $allow): DatabaseEvidenceJournal
    {
        $policy = new class($allow) implements EvidenceAuthorization
        {
            public function __construct(private bool $allow) {}

            public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
            {
                if (! $this->allow || $actorReference !== 'reviewer.42' || $operation->scope !== 'payments.capture') {
                    throw new RuntimeException('Access denied.');
                }
            }
        };

        return new DatabaseEvidenceJournal($this->db, $policy, $this->operations, $this->evidence);
    }

    private function observation(): ReconciliationEvidence
    {
        return new ReconciliationEvidence(
            ReconciliationOutcome::ConfirmedSuccess,
            'gateway.status',
            'capture_found',
            new DateTimeImmutable('2026-10-12T05:00:00+00:00'),
            'private-ref-not-to-be-stored',
        );
    }

    public function test_authorized_evidence_is_persisted_without_changing_operation_state(): void
    {
        $event = $this->journal(true)->append($this->operationId, $this->observation(), 'reviewer.42');
        self::assertSame($this->operationId, $event->operationId);
        self::assertSame(1, $this->db->table($this->evidence)->count());
        self::assertSame('requires_reconciliation', $this->db->table($this->operations)->value('status'));
        $row = $this->db->table($this->evidence)->first();
        self::assertNotNull($row);
        self::assertSame('capture_found', $row->evidence_code);
        self::assertStringNotContainsString('private-ref-not-to-be-stored', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    public function test_denial_rolls_back_without_partial_log_row(): void
    {
        $this->expectException(RuntimeException::class);
        try {
            $this->journal(false)->append($this->operationId, $this->observation(), 'reviewer.42');
        } finally {
            self::assertSame(0, $this->db->table($this->evidence)->count());
        }
    }

    public function test_completed_row_cannot_receive_new_evidence(): void
    {
        $this->db->table($this->operations)->where('id', $this->operationId)->update(['status' => 'completed']);
        $this->expectException(InvalidTransition::class);
        $this->journal(true)->append($this->operationId, $this->observation(), 'reviewer.42');
    }
}
