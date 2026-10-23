<?php

namespace App\Http\Controllers;

use App\Models\{GuardianAuthorization, User};
use App\Services\AgeEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Mail, RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgeAuthorizationController extends Controller
{
    public function status(Request $request, AgeEligibility $eligibility)
    {
        $user = $request->user();
        if (($eligibility->age($user) ?? 0) >= 18) {
            DB::transaction(function () use ($user, $eligibility) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (GuardianAuthorization::where('user_id', $user->id)->whereIn('status', ['pending', 'confirmed', 'authorized', 'withdrawal_confirmed'])->exists()) {
                    $eligibility->endAuthorizations($user, 'adulthood');
                    $eligibility->event($user, 'guardian_authority_ended_at_adulthood');
                }
            });
        }
        $authorization = GuardianAuthorization::where('user_id', $user->id)->latest()->first();
        $cases = DB::table('age_support_cases')->where('user_id', $user->id)->latest()->limit(5)->get(['id', 'kind', 'status', 'resolution']);
        foreach ($cases as $case) $case->resolution = $case->resolution ? decrypt($case->resolution) : null;
        return response()->json(['data' => [...$eligibility->state($user), 'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'cases' => $cases, 'authorization' => $authorization?->only(['id', 'status', 'expires_at', 'session_expires_at', 'email_confirmed_at', 'authorized_at'])]])->header('Cache-Control', 'private, no-store');
    }

    public function declare(Request $request, AgeEligibility $eligibility)
    {
        $data = $request->validate(['date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'residence_country' => ['required', new \App\Rules\ResidenceCountry]]);
        return DB::transaction(function () use ($request, $data, $eligibility) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (($user->age_declared_at && $user->residence_country) || ($user->date_of_birth && $user->date_of_birth->format('Y-m-d') !== $data['date_of_birth'])) {
                return response()->json(['code' => 'age_correction_required', 'message' => 'A date-of-birth correction needs a support case.'], 409);
            }
            $user->forceFill([...$data, 'age_declared_at' => now()])->save();
            $eligibility->event($user, 'age_declared', $data);
            return response()->json(['data' => $eligibility->state($user)]);
        });
    }

    public function country(Request $request, AgeEligibility $eligibility)
    {
        $data = $request->validate(['residence_country' => ['required', new \App\Rules\ResidenceCountry]]);
        return DB::transaction(function () use ($request, $data, $eligibility) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->residence_country !== $data['residence_country']) {
                $eligibility->endAuthorizations($user, 'country_changed');
                $user->forceFill($data)->save();
                $eligibility->event($user, 'residence_changed', $data);
            }
            return response()->json(['data' => $eligibility->state($user)]);
        });
    }

    public function invite(Request $request, AgeEligibility $eligibility)
    {
        if (! $eligibility->invitationsReady()) return response()->json([
            'code' => 'guardian_unavailable', 'message' => 'Guardian invitations are not available yet. Please contact support if you need help.',
        ], 503);
        $data = $request->validate(['guardian_name' => 'required|string|max:120', 'guardian_email' => 'required|email|max:190',
            'relationship' => 'required|in:parent,legal_guardian']);
        $user = $request->user();
        if (! $user->email_verified_at) return response()->json(['code' => 'guardian_email_unverified', 'message' => 'Verify your own email before inviting a guardian.'], 403);
        if ($user->age_disputed) return response()->json(['code' => 'age_case_open', 'message' => 'Resolve your open age or guardian case with the team before sending an invitation.'], 409);
        if (($eligibility->age($user) ?? 0) < 13 || $eligibility->age($user) >= 18) return response()->json(['code' => 'guardian_not_applicable', 'message' => 'Check your age details before inviting a guardian.'], 403);
        $email = mb_strtolower(trim($data['guardian_email']));
        if ($email === mb_strtolower($user->email)) return response()->json(['code' => 'guardian_email_matches', 'message' => 'Use your guardian’s own email address.'], 422);
        $emailHash = hash_hmac('sha256', $email, config('app.key'));
        foreach (['guardian-minute:'.$user->id => [1, 60], 'guardian-day:'.$user->id => [3, 86400],
            'guardian-recipient:'.$emailHash => [5, 86400], 'guardian-ip:'.$request->ip() => [10, 86400]] as $key => [$limit, $seconds]) {
            if (! RateLimiter::attempt($key, $limit, fn () => true, $seconds)) {
                $retry = max(1, RateLimiter::availableIn($key));
                return response()->json(['code' => 'guardian_rate_limited', 'message' => 'Please wait before requesting another invitation.',
                    'retry_after' => $retry], 429)->header('Retry-After', $retry);
            }
        }
        $token = bin2hex(random_bytes(32));
        $authorization = DB::transaction(function () use ($user, $eligibility, $data, $email, $emailHash, $token) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->age_disputed, 409, 'Resolve the open age case first.');
            $eligibility->endAuthorizations($user, 'replaced');
            $authorization = GuardianAuthorization::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
                'guardian_name' => $data['guardian_name'], 'guardian_email' => $email, 'email_hash' => $emailHash,
                'relationship' => $data['relationship'], 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48), 'erase_at' => now()->addDays(30)]);
            $eligibility->event($user, 'guardian_invited', [], $authorization->id);
            return $authorization;
        });
        // Token is in the fragment, which is not sent in HTTP requests, logs or referrers.
        $url = rtrim(config('age.website_url'), '/').'/guardian-authorization#'.$token;
        try {
            Mail::raw("A Cozy Health account has requested parent or legal guardian authorization. If you were expecting this request, review it here:\n".$url."\nThis link expires in 48 hours. Opening it does not authorize participation. No private records are shared. If this was unexpected, you may ignore it or report it to Team@cozyhealth.org.",
                fn ($mail) => $mail->to($email)->subject('Cozy Health guardian authorization request'));
        } catch (\Throwable) {
            $authorization->update(['status' => 'delivery_failed', 'token_hash' => null]);
            $eligibility->event($user, 'guardian_delivery_failed', [], $authorization->id);
            return response()->json(['code' => 'guardian_delivery_failed', 'message' => 'The invitation could not be sent. Your details have been kept; please wait and retry.'], 503);
        }
        return response()->json(['data' => ['id' => $authorization->id, 'status' => 'pending']], 201);
    }

    public function cancel(Request $request, AgeEligibility $eligibility)
    {
        return DB::transaction(function () use ($request, $eligibility) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $eligibility->endAuthorizations($user, 'cancelled');
            $eligibility->event($user, 'guardian_cancelled');
            return response()->json(['message' => 'Authorization cancelled. Private records have been preserved.']);
        });
    }

    public function confirm(Request $request, AgeEligibility $eligibility)
    {
        $data = $request->validate(['token' => 'required|regex:/^[a-f0-9]{64}$/', 'session' => 'sometimes|regex:/^[a-f0-9]{64}$/']);
        // The website prepares this random HttpOnly credential before consuming the
        // link, so it can recover even when the confirmation response is lost.
        $session = $data['session'] ?? bin2hex(random_bytes(32));
        return DB::transaction(function () use ($data, $session, $eligibility) {
            $candidate = GuardianAuthorization::where('token_hash', hash('sha256', $data['token']))->firstOrFail();
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $authorization = GuardianAuthorization::whereKey($candidate->id)->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->firstOrFail();
            abort_unless(in_array($authorization->status, ['pending', 'authorized', 'withdrawal_confirmed']) && $authorization->expires_at?->isFuture(), 410, 'This link has expired. Request a new link.');
            $withdraw = in_array($authorization->status, ['authorized', 'withdrawal_confirmed']);
            abort_if(($eligibility->age($user) ?? 18) >= 18, 410, 'Guardian authorization no longer applies.');
            $authorization->update(['status' => $withdraw ? 'withdrawal_confirmed' : 'confirmed', 'token_hash' => null,
                'email_confirmed_at' => now(), 'session_hash' => hash('sha256', $session), 'session_expires_at' => now()->addMinutes(20)]);
            $eligibility->event($user, 'guardian_email_confirmed', [], $authorization->id);
            return response()->json(['session' => $session, ...$this->reviewData($authorization, $user, $eligibility)]);
        });
    }

    private function reviewData(GuardianAuthorization $authorization, User $user, AgeEligibility $eligibility): array
    {
        $completed = in_array($authorization->status, ['authorized', 'declined', 'disputed', 'withdrawn'], true);
        if ($completed) return ['completed' => true, 'decision' => $authorization->status,
            'message' => $authorization->status === 'authorized'
                ? 'Authorization recorded. Access still depends on age, country and consent requirements. No private records have been shared.'
                : 'Your decision has been recorded.'];
        return ['completed' => false, 'withdrawal' => $authorization->status === 'withdrawal_confirmed',
            'expires_at' => $authorization->session_expires_at->toIso8601String(),
            'account_first_name' => explode(' ', trim($user->name))[0], 'documents' => $eligibility->documents(),
            'versions' => $eligibility->versions(), 'documents_ready' => $eligibility->documentsReady()];
    }

    public function review(Request $request, AgeEligibility $eligibility)
    {
        $data = $request->validate(['session' => 'required|regex:/^[a-f0-9]{64}$/']);
        return DB::transaction(function () use ($data, $eligibility) {
            $candidate = GuardianAuthorization::where('session_hash', hash('sha256', $data['session']))->first();
            abort_unless($candidate, 410, 'This review is unavailable or has expired. Reopen your email link or request a new one.');
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $authorization = GuardianAuthorization::whereKey($candidate->id)->where('session_hash', hash('sha256', $data['session']))->lockForUpdate()->first();
            abort_unless($authorization && $authorization->session_expires_at?->isFuture()
                && in_array($authorization->status, ['confirmed', 'withdrawal_confirmed', 'authorized', 'declined', 'disputed', 'withdrawn'], true),
                410, 'This review is unavailable or has expired. Reopen your email link or request a new one.');
            abort_if(($eligibility->age($user) ?? 18) >= 18, 410, 'Guardian authorization no longer applies.');
            return response()->json($this->reviewData($authorization, $user, $eligibility));
        });
    }

    public function decide(Request $request, AgeEligibility $eligibility)
    {
        $data = $request->validate(['session' => 'required|regex:/^[a-f0-9]{64}$/', 'decision' => 'required|in:authorize,decline,not_guardian,withdraw',
            'adult_and_authority' => 'required_if:decision,authorize|boolean', 'versions' => 'required_if:decision,authorize|array']);
        return DB::transaction(function () use ($data, $eligibility) {
            $candidate = GuardianAuthorization::where('session_hash', hash('sha256', $data['session']))->whereIn('status', ['confirmed', 'withdrawal_confirmed'])->firstOrFail();
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $authorization = GuardianAuthorization::whereKey($candidate->id)->where('session_hash', hash('sha256', $data['session']))->lockForUpdate()->firstOrFail();
            abort_unless($authorization->session_expires_at?->isFuture(), 410, 'Your session expired. Request a new link.');
            abort_if(($eligibility->age($user) ?? 18) >= 18, 410, 'Guardian authorization no longer applies.');
            if ($data['decision'] === 'withdraw') abort_unless($authorization->status === 'withdrawal_confirmed', 409);
            else abort_unless($authorization->status === 'confirmed', 409);
            if ($data['decision'] === 'authorize') {
                abort_unless($eligibility->invitationsReady() && ! $user->age_disputed && ($data['adult_and_authority'] ?? false)
                    && $data['versions'] == $eligibility->versions(), 409, 'Review the current effective documents before authorizing.');
            }
            $status = match ($data['decision']) { 'authorize' => 'authorized', 'decline' => 'declined', 'not_guardian' => 'disputed', 'withdraw' => 'withdrawn' };
            // Terminal status consumes decision authority. Keep the hashed credential
            // only until its original expiry to recover a lost decision receipt.
            $authorization->update(['status' => $status,
                'authorized_at' => $status === 'authorized' ? now() : $authorization->authorized_at,
                'document_versions' => $status === 'authorized' ? $eligibility->versions() : $authorization->document_versions,
                'ended_at' => $status === 'authorized' ? null : now(),
                'erase_at' => $status === 'authorized' ? null : now()->addDays($status === 'withdrawn' ? max(1, (int) config('age.record_days')) : 30)]);
            if ($status === 'disputed') {
                $user->forceFill(['age_disputed' => true])->save();
                $this->newCase($user, 'guardian_dispute', 'The invited recipient reported that they are not the guardian.');
            }
            $eligibility->event($user, 'guardian_'.$status, ['assurance' => 'email_and_self_declaration'], $authorization->id);
            return response()->json(['message' => $status === 'authorized' ? 'Authorization recorded. Access still depends on age, country and consent requirements. No private records have been shared.' : 'Your decision has been recorded.']);
        });
    }

    public function requestWithdrawal(Request $request, AgeEligibility $eligibility)
    {
        abort_unless($eligibility->websiteReady(), 503, 'The guardian website is not available yet. Please contact support.');
        $data = $request->validate(['email' => 'required|email|max:190']);
        $hash = hash_hmac('sha256', mb_strtolower(trim($data['email'])), config('app.key'));
        $key = 'guardian-withdrawal:'.$hash;
        abort_unless(RateLimiter::attempt($key, 3, fn () => true, 86400), 429);
        GuardianAuthorization::where('email_hash', $hash)->whereIn('status', ['authorized', 'withdrawal_confirmed'])->each(function ($authorization) {
            $user = User::find($authorization->user_id);
            if (! $user || (app(AgeEligibility::class)->age($user) ?? 18) >= 18) return;
            $token = bin2hex(random_bytes(32));
            // Keep authorization valid while awaiting the guardian's explicit withdrawal.
            $authorization->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48)]);
            $url = rtrim(config('age.website_url'), '/').'/guardian-authorization#'.$token;
            try { Mail::raw('Confirm withdrawal of your Cozy Health guardian authorization: '.$url.' . No records are disclosed by this link.', fn ($mail) => $mail->to($authorization->guardian_email)->subject('Cozy Health: confirm guardian withdrawal')); }
            catch (\Throwable) { /* Generic public response avoids revealing account existence. */ }
        });
        return response()->json(['message' => 'If an active authorization matches, a confirmation link will be sent.']);
    }

    private function newCase(User $user, string $kind, string $message): string
    {
        $id = (string) Str::uuid();
        DB::table('age_support_cases')->insert(['id' => $id, 'user_id' => $user->id, 'kind' => $kind,
            'message' => encrypt($message), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function support(Request $request, AgeEligibility $eligibility)
    {
        $data = $request->validate(['kind' => 'required|in:dob_correction,guardian_dispute,appeal,privacy', 'message' => 'required|string|min:5|max:2000']);
        return DB::transaction(function () use ($request, $data, $eligibility) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (in_array($data['kind'], AgeEligibility::BLOCKING_CASE_KINDS, true)) {
                $user->forceFill(['age_disputed' => true])->save();
                $eligibility->endAuthorizations($user, 'disputed');
            }
            $id = $this->newCase($user, $data['kind'], $data['message']);
            $eligibility->event($user, 'support_case_opened', ['case_id' => $id]);
            return response()->json(['data' => ['id' => $id], 'message' => 'Your case is in the Cozy Health team queue. Do not send identity documents or private journals by email.'], 201);
        });
    }
}
