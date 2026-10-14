<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use Mykholy\Flowguard\Contracts\EvidenceJournal;
use Mykholy\Flowguard\Contracts\Reconciler;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Reconciliation\DatabaseManualResolver;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationInspector;
use Mykholy\Flowguard\Reconciliation\ResolutionDecision;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Domain\Acquisition;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use Mykholy\Flowguard\Inbox\InboxReceipt;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Mykholy\Flowguard\Outbox\OutboxLease;
use Mykholy\Flowguard\Outbox\OutboxMessage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Draft semantic-versioning baseline for the consumer-facing extension points.
 * Update only with documented API review; this is not a v1 release declaration.
 */
final class PublicApiContractTest extends TestCase
{
    public function test_the_documented_core_entry_points_remain_source_compatible(): void
    {
        $cases = [
            [Flowguard::class, 'begin',
                'begin(scope:string,idempotencyKey:string,payload:array):'.Acquisition::class],
            [Flowguard::class, 'complete',
                'complete(claim:'.Claim::class.',safeResultMetadata:array):'.OperationSnapshot::class],
            [Flowguard::class, 'requireReconciliation',
                'requireReconciliation(claim:'.Claim::class.',reason:string):'.OperationSnapshot::class],
            [DatabaseOutboxRecorder::class, 'record',
                'record(scope:string,eventId:string,topic:string,subjectReference:string):'.OutboxMessage::class],
            [EvidenceJournal::class, 'append',
                'append(operationId:string,evidence:'.ReconciliationEvidence::class.',actorReference:string):'.EvidenceEntry::class],
            [DatabaseEvidenceJournal::class, 'append',
                'append(operationId:string,evidence:'.ReconciliationEvidence::class.',actorReference:string):'.EvidenceEntry::class],
            [Reconciler::class, 'inspect',
                'inspect(operation:'.OperationSnapshot::class.'):'.ReconciliationEvidence::class],
            [ReconciliationInspector::class, 'inspect',
                'inspect(operation:'.OperationSnapshot::class.'):'.ReconciliationEvidence::class],
            [DatabaseManualResolver::class, 'resolve',
                'resolve(operationId:string,evidenceId:string,initiatorReference:string,approverReference:string,approvalReference:string):'.ResolutionDecision::class],
            [OutboxPublisher::class, 'publish',
                'publish(message:'.OutboxMessage::class.'):void'],
            [DatabaseInboxRecorder::class, 'receive',
                'receive(scope:string,eventId:string,payload:array,topic:string,subjectReference:string):'.InboxReceipt::class],
            [DatabaseOutboxLeaseStore::class, 'reserve',
                'reserve(leaseSeconds:int):?'.OutboxLease::class],
            [DatabaseOutboxLeaseStore::class, 'acknowledge',
                'acknowledge(lease:'.OutboxLease::class.'):void'],
            [DatabaseOutboxLeaseStore::class, 'release',
                'release(lease:'.OutboxLease::class.',failureCode:string,retryAfterSeconds:int):void'],
            [OutboxDispatcher::class, 'dispatchOne', 'dispatchOne():bool'],
        ];

        foreach ($cases as [$class, $method, $expected]) {
            self::assertSame($expected, self::signature($class, $method), $class.'::'.$method);
        }
    }

    /** @param  class-string  $class */
    private static function signature(string $class, string $name): string
    {
        $method = new ReflectionMethod($class, $name);
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName().':'.$parameter->getType(),
            $method->getParameters(),
        );

        return $name.'('.implode(',', $parameters).'):'.$method->getReturnType();
    }
}
