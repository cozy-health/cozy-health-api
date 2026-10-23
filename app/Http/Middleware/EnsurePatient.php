<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsurePatient
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user instanceof User) $user->refresh();
        abort_unless($user instanceof User && $user->is_active && $user->role === 'user', 403);
        // Explicit recovery/privacy allowlist; all other patient routes are protected by default.
        $identityRead = $request->isMethod('GET') && $request->is('api/v1/user/profile', 'api/v1/me');
        if (! $identityRead && ! $request->is('api/v1/me/eligibility', 'api/v1/me/age', 'api/v1/me/residence', 'api/v1/me/guardian',
            'api/v1/me/age-support', 'api/v1/me/consents', 'api/v1/user/consents', 'api/v1/auth/*',
            'api/v1/me/sessions*', 'api/v1/user/sessions*', 'api/v1/me/password', 'api/v1/account',
            'api/v1/support/reports', 'api/v1/crisis/resources', 'api/v1/store/config')) {
            if (app(\App\Services\DocumentConsentService::class)->configuration()['enforce_blocking']) {
                return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $next, $user) {
                    // Serialize protected operations against consent, country and guardian changes.
                    $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    app(\App\Services\DocumentConsentService::class)->requirePatientConsent($current);
                    $request->setUserResolver(fn () => $current);
                    return $next($request);
                });
            }
        }
        return $next($request);
    }
}
