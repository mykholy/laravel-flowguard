<?php

declare(strict_types=1);

namespace App\Demo;

use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Outbox\OutboxMessage;
use RuntimeException;

final readonly class OrderPublisher implements OutboxPublisher
{
    public function __construct(private ProviderClient $provider) {}

    public function publish(OutboxMessage $message): void
    {
        $order = DB::table('demo_orders')->where('id', $message->subjectReference)->first();
        if ($order === null || ! in_array($message->topic, ['order.created', 'payment.received'], true)
            || $message->scope !== 'tenant.'.$order->tenant_id.':orders') {
            throw new RuntimeException('Publisher scope does not match the immutable order.');
        }
        $this->provider->accept($message->id, (int) $order->tenant_id, $message->topic, $order->id, (int) $order->amount, $order->currency);
        if (app()->environment('testing') && config('demo.crash_after_publish')) {
            // Test-only independent-process termination after remote commit, before local ACK.
            exit(73);
        }
    }
}
