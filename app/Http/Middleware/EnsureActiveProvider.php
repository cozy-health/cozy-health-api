<?php

namespace App\Http\Middleware;

use App\Models\Provider;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveProvider
{
    public function handle(Request $request, Closure $next)
    {
        // Sanctum may resolve a web session before inspecting a bearer token.
        abort_unless($request->user() instanceof Provider, 401, 'Unauthenticated.');
        abort_unless($request->user()->is_active, 403, 'This account has been disabled.');
        return $next($request);
    }
}
