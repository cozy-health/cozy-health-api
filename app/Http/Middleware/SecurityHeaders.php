<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        foreach ([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'",
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ] as $name => $value) { $response->headers->set($name, $value); }
        if ($request->is('api/v1/guardian/*', 'api/v1/me/eligibility', 'api/v1/me/age', 'api/v1/me/residence',
            'api/v1/me/guardian', 'api/v1/me/age-support', 'api/v1/admin/age-cases*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }
}
