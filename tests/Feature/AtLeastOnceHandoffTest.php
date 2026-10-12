<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Mykholy\Flowguard\Outbox\OutboxMessage;
use Psr\Log\NullLogger;
use RuntimeException;

final class AtLeastOnceHandoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('flowguard_handoff_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->string('topic', 120);
            $table->string('subject_ref', 128);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->char('lease_hash', 64)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('available_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->string('last_failure_code', 120)->nullable();
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash']);
        });
    }

    public function test_ambiguous_publish_timeout_retries_without_duplicate_inbox_effect(): void
    {
        DB::transaction(function (): void {
            (new DatabaseOutboxRecorder(DB::connection(), 'flowguard_handoff_outbox'))
                ->record('tenant.42:orders', 'order-paid-101', 'order.paid', 'order-101');
        });

        $publisher = new class(new DatabaseInboxRecorder(DB::connection())) implements OutboxPublisher
        {
            public int $attempts = 0;

            public function __construct(private DatabaseInboxRecorder $inbox) {}

            public function publish(OutboxMessage $message): void
            {
                $this->attempts++;
                $this->inbox->receive(
                    'tenant.42:receiver',
                    $message->id,
                    ['topic' => $message->topic],
                    'delivery.accepted',
                    'receipt-'.$message->id,
                );

                if ($this->attempts === 1) {
                    throw new RuntimeException('Provider accepted but acknowledgement was lost.');
                }
            }
        };

        $dispatcher = new OutboxDispatcher(
            new DatabaseOutboxLeaseStore(DB::connection(), 'flowguard_handoff_outbox'),
            $publisher,
            new NullLogger,
            60,
            0,
        );

        try {
            $dispatcher->dispatchOne();
            self::fail('Expected an ambiguous first delivery.');
        } catch (RuntimeException $exception) {
            self::assertSame('Provider accepted but acknowledgement was lost.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('flowguard_inbox')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->count());
        self::assertSame('pending', DB::table('flowguard_handoff_outbox')->value('status'));

        self::assertTrue($dispatcher->dispatchOne());
        self::assertSame(2, $publisher->attempts);
        self::assertSame('delivered', DB::table('flowguard_handoff_outbox')->value('status'));
        self::assertSame(1, DB::table('flowguard_inbox')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->count());
    }
}
