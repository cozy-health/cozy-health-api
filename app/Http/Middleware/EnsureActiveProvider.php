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
        app(\App\Services\SignInSecurity::class)->requireSession($request);
        // Keep identity, document review and logout available for recovery.
        $identityRead = $request->isMethod('GET') && $request->is('api/v1/provider/me');
        if (! $identityRead && ! $request->is('api/v1/provider/consent-config', 'api/v1/provider/me/consents',
            'api/v1/provider/auth/logout', 'api/v1/provider/auth/revoke-refresh')) {
            $consent = app(\App\Services\DocumentConsentService::class);
            if ($consent->configuration(true)['enforce_blocking']) {
                return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $next, $consent) {
                    $current = Provider::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                    $consent->requireProviderConsent($current);
                    $request->setUserResolver(fn () => $current);
                    return $next($request);
                });
            }
        }
        return $next($request);
    }
}
