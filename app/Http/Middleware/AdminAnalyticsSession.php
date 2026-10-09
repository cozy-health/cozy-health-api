<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AdminAnalyticsSession
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->name === 'cozy-admin-dashboard'
            && $token->can('admin:metrics') && $token->expires_at && $token->expires_at->isFuture(), 403, 'Admin analytics session required.');
        return $next($request);
    }
}
