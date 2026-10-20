<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class VerifyBrevoToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('services.brevo.webhook_token');
        abort_unless(is_string($expected) && strlen($expected) >= 32 && hash_equals($expected, (string) $request->bearerToken()), 401, 'Invalid webhook credentials.');
        return $next($request);
    }
}
