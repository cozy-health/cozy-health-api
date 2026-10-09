<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictAdminAnalyticsToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->name === 'cozy-admin-dashboard') {
            $allowed = $request->is('api/v1/admin/metrics/*', 'admin/metrics/*',
                'api/v1/admin/auth/session', 'api/v1/admin/auth/logout');
            abort_unless($allowed, 403, 'This credential grants aggregate analytics access only.');
        }
        return $next($request);
    }
}
