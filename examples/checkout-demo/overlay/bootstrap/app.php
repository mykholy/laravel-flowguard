<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Mykholy\Flowguard\Exceptions\IdempotencyConflict;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Exceptions\ResolutionRejected;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (IdempotencyConflict $error) => response()->json(['error' => 'idempotency_conflict'], 409));
        $exceptions->render(fn (InvalidTransition $error) => response()->json(['error' => 'invalid_transition'], 409));
        $exceptions->render(fn (ResolutionRejected $error) => response()->json(['error' => 'resolution_rejected'], 409));
    })->create();
