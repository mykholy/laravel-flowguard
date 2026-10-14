<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Contracts\ResolutionAuthorization;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Infrastructure\DatabaseOperationStore;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Reconciliation\DatabaseManualResolver;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;

require dirname(__DIR__).'/Concurrency/bootstrap.php';

if (getenv('FLOWGUARD_RESTORE_CONFIRM') !== 'disposable-only') {
    throw new InvalidArgumentException('Full-schema restore test requires disposable-only confirmation.');
}

$mode = $argv[1] ?? '';
if (! in_array($mode, ['prepare', 'verify'], true)) {
    throw new InvalidArgumentException('Expected prepare or verify.');
}

$source = flowguardConcurrencyConnection();
$expectedDatabase = $mode === 'prepare' ? 'flowguard_ci' : 'flowguard_restore_ci';

if ($mode === 'prepare') {
    $db = $source;
} else {
    $configuration = $source->getConfig();
    $configuration['database'] = $expectedDatabase;
    $capsule = new Capsule;
    $capsule->addConnection($configuration);
    $db = $capsule->getConnection();
}

if ($db->getDatabaseName() !== $expectedDatabase) {
    throw new RuntimeException('Refusing to touch an unexpected database.');
}

$tables = [
    'flowguard_operations',
    'flowguard_reconciliation_evidence',
    'flowguard_resolution_decisions',
    'flowguard_outbox',
    'flowguard_inbox',
];

foreach ($tables as $table) {
    if (! $db->getSchemaBuilder()->hasTable($table)) {
        throw new RuntimeException('Actual package migration is missing '.$table.'.');
    }
}

if ($mode === 'prepare') {
    foreach ($tables as $table) {
        if ($db->table($table)->exists()) {
            throw new RuntimeException('Refusing to overwrite pre-existing Flowguard records.');
        }
    }

    $operationStore = new DatabaseOperationStore($db);
    $identity = OperationIdentity::from('tenant.restore:payments', 'capture-restore-01', ['amount_minor' => 4200]);
    $acquisition = $operationStore->acquire($identity);

    if (! $acquisition->acquired()) {
        throw new RuntimeException('Could not acquire the restored operation.');
    }

    $operationStore->requireReconciliation($acquisition->claim(), 'provider_timeout');

    $evidencePolicy = new class implements EvidenceAuthorization
    {
        public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
        {
            if ($actorReference !== 'reviewer.one' || $operation->scope !== 'tenant.restore:payments') {
                throw new RuntimeException('Test policy rejected evidence.');
            }
        }
    };

    $evidence = (new DatabaseEvidenceJournal($db, $evidencePolicy))->append(
        $acquisition->operation->id,
        new ReconciliationEvidence(
            ReconciliationOutcome::ConfirmedSuccess,
            'gateway.status',
            'capture_found',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'discard-this-private-provider-reference',
        ),
        'reviewer.one',
    );

    $resolutionPolicy = new class implements ResolutionAuthorization
    {
        public function assertApproved(
            string $initiatorReference,
            string $approverReference,
            string $approvalReference,
            OperationSnapshot $operation,
            EvidenceEntry $evidence,
        ): void {
            if ($initiatorReference !== 'reviewer.one'
                || $approverReference !== 'reviewer.two'
                || $approvalReference !== 'approval.restore.2026'
                || $operation->scope !== 'tenant.restore:payments'
                || $operation->id !== $evidence->operationId) {
                throw new RuntimeException('Test approval did not match the saved operation and evidence.');
            }
        }
    };

    (new DatabaseManualResolver($db, $resolutionPolicy))->resolve(
        $acquisition->operation->id,
        $evidence->id,
        'reviewer.one',
        'reviewer.two',
        'approval.restore.2026',
    );

    (new DatabaseInboxRecorder($db))->receive(
        'tenant.restore:webhooks',
        'provider-delivery-01',
        ['type' => 'capture.confirmed', 'amount_minor' => 4200],
        'payment.captured',
        'capture-restore-01',
    );

    $db->transaction(static function () use ($db): void {
        $recorder = new DatabaseOutboxRecorder($db);
        $recorder->record('tenant.restore:outbox', 'pending-01', 'restore.pending', 'pending-01');
        $recorder->record('tenant.restore:outbox', 'leased-01', 'restore.leased', 'leased-01');
        $recorder->record('tenant.restore:outbox', 'delivered-01', 'restore.delivered', 'delivered-01');
    });

    $now = now();
    $db->table('flowguard_outbox')->where('topic', 'restore.leased')->update([
        'status' => 'leased',
        'lease_hash' => hash('sha256', 'fixture-only-lease-token'),
        'lease_expires_at' => $now->copy()->addHour(),
        'delivery_attempts' => 2,
    ]);
    $db->table('flowguard_outbox')->where('topic', 'restore.delivered')->update([
        'status' => 'delivered',
        'delivery_attempts' => 1,
        'delivered_at' => $now,
    ]);

    echo "Seeded every Flowguard table using real migrations and library APIs.\n";
    exit(0);
}

$operation = $db->table('flowguard_operations')->where('scope', 'tenant.restore:payments')->sole();
$evidence = $db->table('flowguard_reconciliation_evidence')->where('operation_id', $operation->id)->sole();
$decision = $db->table('flowguard_resolution_decisions')->where('operation_id', $operation->id)->sole();
$inbox = $db->table('flowguard_inbox')->sole();
$inboxOutbox = $db->table('flowguard_outbox')->where('id', $inbox->outbox_id)->sole();

if ($operation->status !== OperationStatus::ReconciledSuccess->value
    || $evidence->outcome !== ReconciliationOutcome::ConfirmedSuccess->value
    || (int) $evidence->provider_reference_present !== 1
    || $decision->evidence_id !== $evidence->id
    || $decision->status !== OperationStatus::ReconciledSuccess->value
    || $inboxOutbox->topic !== 'payment.captured') {
    throw new RuntimeException('Full schema restore lost reconciliation or inbox/outbox linkage.');
}

foreach ([
    ['restore.pending', 'pending', 0],
    ['restore.leased', 'leased', 2],
    ['restore.delivered', 'delivered', 1],
] as [$topic, $status, $attempts]) {
    $row = $db->table('flowguard_outbox')->where('topic', $topic)->sole();

    if ($row->status !== $status || (int) $row->delivery_attempts !== $attempts) {
        throw new RuntimeException('Restored delivery state mismatch: '.$topic);
    }
}

try {
    $db->table('flowguard_operations')->where('id', $operation->id)->delete();
    throw new RuntimeException('Restored foreign keys did not protect the operation.');
} catch (QueryException) {
    // The durable evidence and decision records must protect the operation.
}

try {
    $db->table('flowguard_outbox')->where('id', $inbox->outbox_id)->delete();
    throw new RuntimeException('Restored inbox foreign key failed to protect the outbox record.');
} catch (QueryException) {
    // Inbox receipts must retain the event signal they reference.
}

$leaseStore = new DatabaseOutboxLeaseStore($db, scope: 'tenant.restore:outbox');
$lease = $leaseStore->reserve(60);

if ($lease === null || $lease->topic !== 'restore.pending' || $lease->attempt !== 1) {
    throw new RuntimeException('Could not safely resume pending delivery after restore.');
}

$leaseStore->acknowledge($lease);

if ($db->table('flowguard_outbox')->where('topic', 'restore.leased')->where('status', 'leased')->count() !== 1
    || $db->table('flowguard_outbox')->where('topic', 'restore.delivered')->where('status', 'delivered')->count() !== 1
    || $db->table('flowguard_outbox')->where('topic', 'restore.pending')->where('status', 'delivered')->count() !== 1) {
    throw new RuntimeException('Post-restore recovery changed unrelated deliveries.');
}

echo "Full Flowguard schema, foreign keys and post-restore leasing verified.\n";
