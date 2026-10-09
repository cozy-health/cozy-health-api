<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireHttps
{
    public function handle(Request $request, Closure $next)
    {
        // Railway's container health probe is internal HTTP and carries no PHI.
        if (config('security.require_https') && ! $request->isSecure() && ! $request->is('up')) {
            return response()->json(['success' => false, 'message' => 'HTTPS is required.'], 426);
        }

        return $next($request);
    }
}
