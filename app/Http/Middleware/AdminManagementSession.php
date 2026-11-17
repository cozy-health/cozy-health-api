<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
class AdminManagementSession
{
    public function handle(Request $request, Closure $next, string $capability)
    {
        if ($request->route('resource') === 'packages') $capability = 'subscriptions';
        if (in_array($capability, ['support', 'privacy'], true)) {
            abort_unless(in_array($request->user()?->id, config('operations.'.$capability.'_reviewers', []), true), 403, 'This reviewer assignment is required.');
        }
        $token = $request->user()?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->name === 'cozy-admin-management-v1'
            && $token->can('admin:'.$capability) && $token->expires_at?->isFuture(), 403, 'This admin capability is required.');
        return $next($request);
    }
}
