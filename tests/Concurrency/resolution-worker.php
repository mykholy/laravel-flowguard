<?php

declare(strict_types=1);

use Mykholy\Flowguard\Contracts\ResolutionAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Reconciliation\DatabaseManualResolver;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;

require __DIR__.'/bootstrap.php';

try {
    $ops = getenv('FG_RES_OPS');
    $ev = getenv('FG_RES_EV');
    $dec = getenv('FG_RES_DEC');
    $operation = getenv('FG_RES_ID');
    $evidence = getenv('FG_RES_EVIDENCE_ID');
    $gate = getenv('FG_RES_GATE');

    foreach ([$ops, $ev, $dec] as $table) {
        if (! is_string($table) || preg_match('/^fg_res_(ops|ev|dec)_[a-f0-9]{12}$/D', $table) !== 1) {
            throw new InvalidArgumentException('Invalid resolution test table.');
        }
    }
    if (! is_string($operation) || ! is_string($evidence) || ! is_string($gate)) {
        throw new InvalidArgumentException('Missing resolution test parameters.');
    }

    $deadline = microtime(true) + 20;
    while (! file_exists($gate)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker gate timeout.');
        }
        usleep(10000);
    }

    $policy = new class implements ResolutionAuthorization
    {
        public function assertApproved(
            string $initiatorReference,
            string $approverReference,
            string $approvalReference,
            OperationSnapshot $operation,
            EvidenceEntry $evidence,
        ): void {
            if ($operation->id !== $evidence->operationId ||
                $initiatorReference !== 'reviewer.one' ||
                $approverReference !== 'reviewer.two' ||
                $approvalReference !== 'approval.2026-42') {
                throw new RuntimeException('Invalid test approval.');
            }
        }
    };

    $resolver = new DatabaseManualResolver(flowguardConcurrencyConnection(), $policy, $ops, $ev, $dec);
    try {
        $decision = $resolver->resolve($operation, $evidence, 'reviewer.one', 'reviewer.two', 'approval.2026-42');
        echo json_encode(['resolved' => true, 'decision' => $decision->id], JSON_THROW_ON_ERROR)."\n";
    } catch (InvalidTransition) {
        echo json_encode(['resolved' => false], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
