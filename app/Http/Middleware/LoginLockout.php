<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoginLockout
{
    public function handle(Request $request, Closure $next)
    {
        $email = strtolower(trim((string) $request->input('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { return $next($request); }
        $request->merge(['email' => $email]);
        $hash = hash_hmac('sha256', $email, config('app.key'));
        // Serialize attempts for the same identity independently of source IP.
        return \Illuminate\Support\Facades\Cache::lock('login:'.$hash, 15)->block(5, function () use ($next, $request, $hash) {
            $failures = fn () => DB::table('security_events')->where('email_hash', $hash)->where('event_type', 'login_failed')->where('created_at', '>', now()->subMinutes(15));
            $blocked = function () use ($failures) {
                $first = $failures()->orderBy('created_at')->value('created_at');
                $seconds = max(1, (int) ceil(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($first)->addMinutes(15), false)));
                return response()->json(['success' => false, 'message' => 'Too many sign-in attempts. Please try later.'], 429)->header('Retry-After', (string) $seconds);
            };
            if ($failures()->count() >= 5) { return $blocked(); }
            $validation = null;
            try {
                $response = $next($request);
                $errors = $response instanceof \Illuminate\Http\JsonResponse ? ($response->getData(true)['errors'] ?? []) : [];
                $failed = $response->getStatusCode() === 422 && ($errors['email'][0] ?? '') === 'The provided credentials are incorrect.';
            }
            catch (ValidationException $e) {
                if (($e->errors()['email'][0] ?? '') !== 'The provided credentials are incorrect.') { throw $e; }
                $validation = $e;
                $failed = true;
            }
            if ($failed) {
                DB::table('security_events')->insert(['user_id' => null, 'email_hash' => $hash,
                    'event_type' => 'login_failed', 'ip_address' => $request->ip(), 'created_at' => now()]);
                if ($failures()->count() >= 5) { return $blocked(); }
            }
            if ($validation) { throw $validation; }
            return $response;
        });
    }
}
