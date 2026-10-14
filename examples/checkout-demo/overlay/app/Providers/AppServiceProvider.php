<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\DemoCrashDispatch;
use App\Console\Commands\DemoCrashOperation;
use App\Console\Commands\DemoRestoreFixture;
use App\Console\Commands\DemoServeProvider;
use App\Demo\OrderPublisher;
use Illuminate\Support\ServiceProvider;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OutboxPublisher::class, OrderPublisher::class);
    }

    public function boot(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo host must run only in local or testing environments.');
        }
        if ($this->app->runningInConsole()) {
            $this->commands([DemoCrashDispatch::class, DemoCrashOperation::class, DemoRestoreFixture::class, DemoServeProvider::class]);
        }
    }
}
