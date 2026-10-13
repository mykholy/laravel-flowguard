<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Outbox\OutboxMessage;
use RuntimeException;

final class DispatchOutboxOnceTest extends TestCase
{
    private function record(string $eventId): void
    {
        DB::transaction(static function () use ($eventId): void {
            (new DatabaseOutboxRecorder(DB::connection()))
                ->record('tenant.42:orders', $eventId, 'order.paid', $eventId);
        });
    }

    public function test_an_application_publisher_delivers_exactly_one_eligible_message_per_invocation(): void
    {
        $this->record('dispatch-1');
        $this->record('dispatch-2');

        $publisher = new class implements OutboxPublisher
        {
            /** @var list<string> */
            public array $sent = [];

            public function publish(OutboxMessage $message): void
            {
                $this->sent[] = $message->id;
            }
        };
        $this->app->instance(OutboxPublisher::class, $publisher);

        $this->artisan('flowguard:dispatch-once')
            ->expectsOutputToContain('One outbox message delivered.')
            ->assertExitCode(0);

        self::assertCount(1, $publisher->sent);
        self::assertSame(1, DB::table('flowguard_outbox')->where('status', 'delivered')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->where('status', 'pending')->count());
    }

    public function test_scoped_command_only_dispatches_an_authorized_tenant_queue(): void
    {
        DB::transaction(function (): void {
            $recorder = new DatabaseOutboxRecorder(DB::connection());
            $recorder->record('tenant.1:orders', 'cmd-one', 'order.paid', 'order-1');
            $recorder->record('tenant.2:orders', 'cmd-two', 'order.paid', 'order-2');
        });

        $publisher = new class implements OutboxPublisher
        {
            /** @var list<string> */
            public array $scopes = [];

            public function publish(OutboxMessage $message): void
            {
                $this->scopes[] = $message->scope;
            }
        };

        $this->app->instance(OutboxPublisher::class, $publisher);
        $this->artisan('flowguard:dispatch-once', ['--scope' => 'tenant.2:orders'])
            ->expectsOutputToContain('One outbox message delivered.')
            ->assertExitCode(0);

        self::assertSame(['tenant.2:orders'], $publisher->scopes);
        self::assertSame('pending', DB::table('flowguard_outbox')
            ->where('scope', 'tenant.1:orders')->value('status'));
        self::assertSame('delivered', DB::table('flowguard_outbox')
            ->where('scope', 'tenant.2:orders')->value('status'));
    }

    public function test_invalid_scope_fails_before_claiming_a_message(): void
    {
        $this->record('dispatch-scoped-invalid');

        $this->artisan('flowguard:dispatch-once', ['--scope' => 'tenant 42'])
            ->expectsOutputToContain('Scope must be a bounded tenant-aware identifier.')
            ->assertExitCode(1);

        self::assertSame(0, DB::table('flowguard_outbox')->sum('delivery_attempts'));
    }

    public function test_missing_publisher_binding_fails_without_acquiring_a_message(): void
    {
        $this->record('dispatch-3');

        $this->artisan('flowguard:dispatch-once')
            ->expectsOutputToContain('Bind OutboxPublisher')
            ->assertExitCode(1);

        self::assertSame(0, DB::table('flowguard_outbox')->sum('delivery_attempts'));
        self::assertSame('pending', DB::table('flowguard_outbox')->value('status'));
    }

    public function test_a_provider_error_is_released_without_its_message_being_logged(): void
    {
        $this->record('dispatch-4');
        $publisher = new class implements OutboxPublisher
        {
            public function publish(OutboxMessage $message): void
            {
                throw new RuntimeException('private provider credential leaked');
            }
        };
        $this->app->instance(OutboxPublisher::class, $publisher);

        $this->artisan('flowguard:dispatch-once', ['--retry' => '0'])
            ->expectsOutputToContain('Outbox delivery failed: '.RuntimeException::class)
            ->assertExitCode(1);

        self::assertSame('pending', DB::table('flowguard_outbox')->value('status'));
        self::assertSame('publish_failed', DB::table('flowguard_outbox')->value('last_failure_code'));
        self::assertSame(1, (int) DB::table('flowguard_outbox')->value('delivery_attempts'));
    }

    public function test_invalid_lease_limits_are_rejected_without_running_a_publisher(): void
    {
        $this->artisan('flowguard:dispatch-once', ['--lease' => '0'])
            ->expectsOutputToContain('Lease must be 5-3600')
            ->assertExitCode(1);
    }
}
