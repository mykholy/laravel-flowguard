<?php

declare(strict_types=1);

namespace Mykholy\Flowguard;

use Illuminate\Support\ServiceProvider;
use Mykholy\Flowguard\Console\DiagnoseFlowguard;
use Mykholy\Flowguard\Console\DispatchOutboxOnce;
use Mykholy\Flowguard\Console\InspectOutbox;
use Mykholy\Flowguard\Console\ListStaleOperations;
use Mykholy\Flowguard\Contracts\OperationStore;
use Mykholy\Flowguard\Infrastructure\DatabaseOperationStore;

final class FlowguardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flowguard.php', 'flowguard');

        $this->app->singleton(OperationStore::class, function ($app): OperationStore {
            /** @var string|null $name */
            $name = config('flowguard.connection');
            /** @var string $table */
            $table = config('flowguard.table', 'flowguard_operations');

            return new DatabaseOperationStore($app['db']->connection($name), $table);
        });

        $this->app->singleton(Flowguard::class, fn ($app): Flowguard => new Flowguard($app->make(OperationStore::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([DiagnoseFlowguard::class, DispatchOutboxOnce::class, InspectOutbox::class, ListStaleOperations::class]);
        }
        $this->publishes([__DIR__.'/../config/flowguard.php' => config_path('flowguard.php')], 'flowguard-config');
    }
}
