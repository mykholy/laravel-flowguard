<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class DemoCrashDispatch extends Command
{
    protected $signature = 'demo:crash-dispatch {tenant}';

    protected $description = 'Testing only: exit after sandbox commit and before outbox acknowledgement';

    public function handle(): int
    {
        if (! app()->environment('testing') || ! in_array($this->argument('tenant'), ['1', '2'], true)) {
            return self::FAILURE;
        }
        config(['demo.crash_after_publish' => true]);

        return $this->call('flowguard:dispatch-once', ['--scope' => 'tenant.'.$this->argument('tenant').':orders', '--retry' => '0']);
    }
}
