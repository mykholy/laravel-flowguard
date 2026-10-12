<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

final class InspectOutboxTest extends TestCase
{
    public function test_expired_and_exhausted_messages_are_reported_without_mutation(): void
    {
        DB::transaction(static function (): void {
            $recorder = new DatabaseOutboxRecorder(DB::connection());
            $recorder->record('tenant.42:orders', 'outbox-1', 'order.paid', 'order-1');
            $recorder->record('tenant.42:orders', 'outbox-2', 'order.paid', 'order-2');
        });

        $ids = DB::table('flowguard_outbox')->orderBy('id')->pluck('id')->all();
        DB::table('flowguard_outbox')->where('id', $ids[0])->update([
            'status' => 'leased',
            'lease_expires_at' => '2020-01-01 00:00:00.000000',
            'delivery_attempts' => 1,
        ]);
        DB::table('flowguard_outbox')->where('id', $ids[1])->update([
            'status' => 'pending',
            'delivery_attempts' => 10,
            'last_failure_code' => 'timeout',
        ]);

        $before = DB::table('flowguard_outbox')->orderBy('id')->get()->toJson();

        $this->artisan('flowguard:outbox', ['--max-attempts' => '10'])
            ->expectsOutputToContain('attempts_exhausted')
            ->assertExitCode(0);

        self::assertSame($before, DB::table('flowguard_outbox')->orderBy('id')->get()->toJson());
    }

    public function test_invalid_limits_fail_before_database_access(): void
    {
        $this->artisan('flowguard:outbox', ['--limit' => '0'])
            ->expectsOutputToContain('Limit must be 1-100')
            ->assertExitCode(1);
    }
}
