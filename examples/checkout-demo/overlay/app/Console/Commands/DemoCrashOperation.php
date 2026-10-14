<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Demo\Checkout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Flowguard;

final class DemoCrashOperation extends Command
{
    protected $signature = 'demo:crash-operation {order}';

    protected $description = 'Testing only: exit with a committed processing claim before a provider call';

    public function handle(Checkout $checkout, Flowguard $guard): int
    {
        if (! app()->environment('testing')) {
            return self::FAILURE;
        }
        $order = $checkout->order(1, $this->argument('order'));
        $acquisition = $guard->begin('tenant.1:payments', $order->id, ['order' => $order->id, 'amount' => (int) $order->amount, 'currency' => $order->currency]);
        if (! $acquisition->acquired()) {
            return self::FAILURE;
        }
        DB::table('demo_payments')->insert(['operation_id' => $acquisition->operation->id, 'order_id' => $order->id, 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        exit(74);
    }
}
