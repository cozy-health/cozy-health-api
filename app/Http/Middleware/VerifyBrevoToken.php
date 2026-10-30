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
        if (config('development_mail.environment_id') === \App\Services\CommunityAvailability::DEVELOPMENT_ENVIRONMENT) {
            $safety = app(\App\Services\DevelopmentMailSafety::class);
            abort_unless($safety->development(), 503, 'Development webhook environment identity is invalid.');
            $custom = $request->input('X-Mailin-custom');
            $email = $request->input('email');
            // Shared Brevo accounts may also send production callbacks. Ignore those
            // before validation, delivery tracking, recipient lookup or persistence.
            if (! is_string($custom) || strlen($custom) > 2048
                || ! in_array(\App\Services\DevelopmentMailSafety::BREVO_MARKER, explode('|', $custom), true)
                || ! is_string($email) || ! $safety->recipientAllowed($email)) {
                return response()->json(['message' => 'Event acknowledged.']);
            }
        }
        return $next($request);
    }
}
