<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HideDebugEndpoints
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment('local', 'testing')) {
            abort_if($request->is('api/documentation', 'docs', 'docs/*', 'telescope', 'telescope/*', '_debugbar', '_debugbar/*'), 404);
        }
        return $next($request);
    }
}
