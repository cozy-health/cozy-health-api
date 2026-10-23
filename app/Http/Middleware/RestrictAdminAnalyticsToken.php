<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictAdminAnalyticsToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->name === 'cozy-admin-management-v1') {
            $path = preg_replace('#^api/v1/admin/#', '', $request->path());
            $allowed = preg_match('#^(operations/summary|auth/(session|logout)|metrics/(overview|usage|retention|safety|insights)|content/(moods|expressions|triggers|coping|communities|challenges|packages)(/\d+)?|users(/\d+)?|quizzes(/\d+(/publish)?)?|reports(/\d+(/target)?)?|support(/\d+)?|subscriptions(/history)?|age-cases(/[a-f0-9-]+/resolve)?|student-applications(/[a-f0-9-]+/(evidence|review))?|emails(/test|/[a-z-]+/preview)?|community/(posts|comments|members)(/[a-f0-9-]+)?)$#', $path);
            abort_unless($allowed, 403, 'This credential grants scoped admin access only.');
        }
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->name === 'cozy-admin-dashboard') {
            $allowed = $request->is('api/v1/admin/metrics/*', 'admin/metrics/*',
                'api/v1/admin/auth/session', 'api/v1/admin/auth/logout');
            abort_unless($allowed, 403, 'This credential grants aggregate analytics access only.');
        }
        return $next($request);
    }
}
