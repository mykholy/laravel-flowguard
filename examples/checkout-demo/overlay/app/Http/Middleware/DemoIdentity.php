<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DemoIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->bearerToken();
        abort_unless(is_string($provided) && $provided !== '', 401);
        foreach (config('demo.principals') as $actor => $principal) {
            if ($principal['token'] !== '' && hash_equals($principal['token'], $provided)) {
                // Identity and tenant are derived from server configuration, never body/headers.
                $request->attributes->set('demo_actor', $actor);
                $request->attributes->set('demo_tenant', $principal['tenant']);
                $request->attributes->set('demo_role', $principal['role']);

                return $next($request);
            }
        }
        abort(401);
    }
}
