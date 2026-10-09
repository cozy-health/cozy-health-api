<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        abort_unless($request->user() instanceof \App\Models\User && $request->user()->is_active
            && ! $request->user()->is_demo && $request->user()->role === $role, 403, 'You do not have permission to access this resource.');
        return $next($request);
    }
}
