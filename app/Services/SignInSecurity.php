<?php

namespace App\Services;

use App\Mail\NewDeviceLoginMail;
use App\Mail\SignInChallengeMail;
use App\Models\Provider;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class SignInSecurity
{
    public const QUESTIONS = [
        'memorable_place' => 'Which memorable place will you use as your secret answer?',
        'memorable_person' => 'Which memorable person will you use as your secret answer?',
        'memorable_word' => 'Which memorable word will you use as your secret answer?',
        'memorable_book' => 'Which memorable book will you use as your secret answer?',
        'memorable_song' => 'Which memorable song will you use as your secret answer?',
        'memorable_object' => 'Which memorable object will you use as your secret answer?',
    ];

    public function emailHash(User|Provider $owner): string
    {
        return hash_hmac('sha256', $owner->getMorphClass().':'.$owner->id.':'.strtolower($owner->email), config('app.key'));
    }

    private function scope(User|Provider $owner)
    {
        return DB::table('signin_security_preferences')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id);
    }

    public function requiresProtection(User|Provider $owner): bool
    {
        return (bool) config('signin_security.enabled') || $this->scope($owner)->exists() || $this->hasProtectionHistory($owner);
    }

    private function hasProtectionHistory(User|Provider $owner): bool
    {
        // Older staged builds created challenges before the durable preference marker.
        return DB::table('signin_challenges')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
            ->where('email_hash', $this->emailHash($owner))->exists();
    }
    private function markRequired(User|Provider $owner): void
    {
        // Existing rows, including in-progress enrollment, are never reset by rollout pause.
        DB::table('signin_security_preferences')->insertOrIgnore(['owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id, 'method' => 'email', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function hasChallenge(string $role, string $token): bool
    {
        $type = $role === 'provider' ? (new Provider)->getMorphClass() : (new User)->getMorphClass();

        return DB::table('signin_challenges')->where('owner_type', $type)->where('token_hash', hash('sha256', $token))->exists();
    }
    public function preferences(User|Provider $owner): array
    {
        $row = $this->scope($owner)->first();
        $questions = $row?->questions ? json_decode($row->questions, true, flags: JSON_THROW_ON_ERROR) : [];

        return ['enabled' => (bool) config('signin_security.enabled') || $row !== null || $this->hasProtectionHistory($owner), 'method' => $row?->method ?? 'email',
            'question_ids' => array_keys($questions), 'enrolled' => $row?->configured_at !== null,
            'questions' => self::QUESTIONS, 'support_email' => 'team@cozyhealth.org'];
    }

    public function trustedHash(User|Provider $owner, ?string $proof): ?string
    {
        if (! is_string($proof) || ! preg_match('/^[a-f0-9]{64}$/', $proof)) {
            return null;
        }
        $email = $this->scope($owner)->first();
        if (! $email || ! $email->configured_at || ! hash_equals((string) $email->verified_email_hash, $this->emailHash($owner))) {
            return null;
        }
        $hash = hash('sha256', $proof);
        $device = DB::table('known_devices')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
            ->where('proof_hash', $hash)->where('last_used_at', '>', now()->subDays(14))->first();

        return $device ? $hash : null;
    }

    public function requireSession(Request $request): void
    {
        $owner = $request->user();
        if ((! $owner instanceof User && ! $owner instanceof Provider) || ! $this->requiresProtection($owner)) {
            return;
        }
        // Recovery settings require password + email verification separately.
        if ($request->is('api/v1/auth/security*', 'api/v1/provider/auth/security*', 'api/v1/auth/logout',
            'api/v1/provider/auth/logout', 'api/v1/crisis/resources')) {
            return;
        }
        if ($request->isMethod('POST') && $request->is('api/v1/support/reports')) {
            return;
        }
        $this->markRequired($owner);
        $token = $owner->currentAccessToken();
        $hash = $token instanceof PersonalAccessToken ? $token->security_device_hash : null;
        $settings = $this->scope($owner)->first();
        $device = is_string($hash) ? DB::table('known_devices')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
            ->where('proof_hash', $hash)->where('last_used_at', '>', now()->subDays(14))->first() : null;
        if (! $device || ! $settings || ! $settings->configured_at || ! hash_equals((string) $settings->verified_email_hash, $this->emailHash($owner))) {
            throw new HttpResponseException(response()->json(['success' => false,
                'code' => 'signin_verification_required', 'message' => 'Sign in again and complete your security check before opening private information.'], 403));
        }
        DB::table('known_devices')->where('id', $device->id)->where('last_used_at', '<', now()->subMinute())->update(['last_used_at' => now()]);
    }

    public function begin(User|Provider $owner, Request $request, string $deviceName, string $purpose = 'login'): array
    {
        $request->validate(['device_proof' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);

        return DB::transaction(function () use ($owner, $deviceName, $purpose) {
            $owner = $owner->newQuery()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->is_active, 403, 'This account has been disabled.');
            $this->markRequired($owner);
            $key = 'signin:start:'.$owner->getMorphClass().':'.$owner->id;
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Please wait before requesting another sign-in check.');
            RateLimiter::hit($key, 900);
            $settings = $this->scope($owner)->first();
            // Questions are usable only after this precise email has been verified.
            $method = $purpose === 'login' && $settings?->method === 'questions'
                && hash_equals((string) $settings->verified_email_hash, $this->emailHash($owner)) ? 'questions' : 'email';
            $questions = $method === 'questions' ? array_keys(json_decode($settings->questions, true, flags: JSON_THROW_ON_ERROR)) : [];
            shuffle($questions);
            $token = bin2hex(random_bytes(32));
            $id = (string) Str::uuid();
            DB::table('signin_challenges')->insert(['id' => $id, 'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
                'token_hash' => hash('sha256', $token), 'email_hash' => $this->emailHash($owner), 'method' => $method,
                'purpose' => $purpose, 'device_name' => mb_substr($deviceName, 0, 120),
                'question_ids' => $method === 'questions' ? json_encode(array_slice($questions, 0, 2), JSON_THROW_ON_ERROR) : null,
                'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
            $delivery = $method === 'email' ? $this->sendCode($owner, $id) : null;

            return $this->challengeResponse(DB::table('signin_challenges')->find($id), $token, $delivery);
        });
    }

    private function challengeResponse(object $row, string $token, ?string $delivery = null): array
    {
        $ids = $row->question_ids ? json_decode($row->question_ids, true, flags: JSON_THROW_ON_ERROR) : [];

        return ['challenge_required' => true, 'challenge_token' => $token, 'method' => $row->method,
            'expires_at' => Carbon::parse($row->expires_at)->toIso8601String(),
            'questions' => array_map(fn ($id) => ['id' => $id, 'prompt' => self::QUESTIONS[$id]], $ids),
            'email_delivery' => $delivery, 'support_email' => 'team@cozyhealth.org'];
    }

    private function sendCode(User|Provider $owner, string $id): string
    {
        if ($owner->email_invalid) {
            return 'unavailable';
        }
        $key = 'signin:email:'.$owner->getMorphClass().':'.$owner->id;
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Please wait before requesting another email code.');
        RateLimiter::hit($key, 3600);
        $code = (string) random_int(100000, 999999);
        DB::table('signin_challenges')->where('id', $id)->update(['method' => 'email', 'code_hash' => Hash::make($code),
            'question_ids' => null, 'last_sent_at' => now(), 'updated_at' => now()]);
        try {
            Mail::to($owner->email)->queue(new SignInChallengeMail($id, $code, $owner->email));

            return 'queued';
        } catch (\Throwable) {
            \Log::warning('Sign-in verification email could not be queued.');

            return 'failed';
        }
    }

    public function resend(string $role, string $token): array
    {
        return DB::transaction(function () use ($role, $token) {
            [$row, $owner] = $this->pending($role, $token);
            abort_if($row->last_sent_at && now()->lt(Carbon::parse($row->last_sent_at)->addSeconds(60)), 429, 'Wait one minute before requesting another code.');
            $delivery = $this->sendCode($owner, $row->id);

            return $this->challengeResponse(DB::table('signin_challenges')->find($row->id), $token, $delivery);
        });
    }

    private function ownerClass(string $role): string
    {
        return $role === 'provider' ? Provider::class : User::class;
    }

    private function pending(string $role, string $token): array
    {
        $class = $this->ownerClass($role);
        $type = (new $class)->getMorphClass();
        $row = DB::table('signin_challenges')->where('owner_type', $type)->where('token_hash', hash('sha256', $token))->first();
        abort_unless($row, 422, 'This sign-in check is no longer available.');
        // Lock owner before challenge, matching settings updates and session issuance.
        $owner = $class::whereKey($row->owner_id)->lockForUpdate()->first();
        $row = DB::table('signin_challenges')->where('id', $row->id)->lockForUpdate()->first();
        abort_unless($row && ! $row->consumed_at && $row->failed_attempts < 5 && now()->lt($row->expires_at), 422, 'This sign-in check expired or was already used. Start sign-in again.');
        abort_unless($owner && $owner->is_active && hash_equals($row->email_hash, $this->emailHash($owner)), 422, 'This sign-in check is no longer available.');

        return [$row, $owner];
    }

    public function verify(string $role, string $token, array $data, Request $request): array
    {
        $notifyOwner = null;
        $notifyDevice = null;
        $result = DB::transaction(function () use ($role, $token, $data, $request, &$notifyOwner, &$notifyDevice) {
            [$row, $owner] = $this->pending($role, $token);
            $limitKey = 'signin:answers:'.$owner->getMorphClass().':'.$owner->id;
            abort_if(RateLimiter::tooManyAttempts($limitKey, 5), 429, 'Too many unsuccessful checks. Please wait before trying again.');
            if ($row->method === 'email') {
                $valid = is_string($data['code'] ?? null) && $row->code_hash && Hash::check($data['code'], $row->code_hash);
            } else {
                $settings = $this->scope($owner)->first();
                $answers = $settings?->questions ? json_decode($settings->questions, true, flags: JSON_THROW_ON_ERROR) : [];
                $valid = true;
                foreach (json_decode($row->question_ids, true, flags: JSON_THROW_ON_ERROR) as $id) {
                    $answer = $data['answers'][$id] ?? null;
                    $matches = is_string($answer) && isset($answers[$id]) && Hash::check($this->answerDigest($answer), $answers[$id]);
                    $valid = $matches && $valid;
                }
            }
            if (! $valid) {
                DB::table('signin_challenges')->where('id', $row->id)->increment('failed_attempts');
                RateLimiter::hit($limitKey, 900);

                // Return, then reject outside the transaction so attempt counts commit.
                return ['invalid' => true];
            }
            DB::table('signin_challenges')->where('id', $row->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            if ($row->method === 'email') {
                $existing = $this->scope($owner)->first();
                if (! $existing) {
                    DB::table('signin_security_preferences')->insert(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
                        'method' => 'email', 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->scope($owner)->update(['verified_email_hash' => $this->emailHash($owner), 'email_verified_at' => now(), 'updated_at' => now()]);
                if ($owner instanceof User && ! $owner->email_verified_at) {
                    $owner->forceFill(['email_verified_at' => now()])->save();
                }
            }
            if ($row->purpose === 'settings') {
                return ['verified' => true, 'security_settings_token' => $token, 'expires_in' => 600];
            }
            $proof = bin2hex(random_bytes(32));
            $hash = hash('sha256', $proof);
            DB::table('known_devices')->insert(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
                'device_hash' => $hash, 'proof_hash' => $hash, 'verified_at' => now(), 'last_used_at' => now(), 'created_at' => now()]);
            $notifyOwner = $owner;
            $notifyDevice = $row->device_name;
            if ($owner instanceof User) {
                app(AnalyticsService::class)->track('user_logged_in');
            }

            return ['challenge_required' => false, 'device_proof' => $proof,
                $owner instanceof Provider ? 'provider' : 'user' => $owner->refresh(),
                'security_enrollment_required' => ! $this->preferences($owner)['enrolled'],
                ...($row->method === 'email' ? ['security_settings_token' => $token] : []),
                ...app(AuthSessions::class)->issue($owner, $request, $row->device_name, false, $hash)];
        });
        abort_if($result['invalid'] ?? false, 422, 'The code or answers were not accepted. Please try again.');
        if ($notifyOwner) {
            DB::afterCommit(function () use ($notifyOwner, $notifyDevice, $request) {
                try {
                    Mail::to($notifyOwner->email)->queue(new NewDeviceLoginMail($notifyDevice, $request->ip(), now()->toIso8601String()));
                } catch (\Throwable) {
                    \Log::warning('New-device notification could not be queued.');
                }
            });
        }

        return $result;
    }

    private function normalize(string $answer): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($answer)));
    }

    private function answerDigest(string $answer): string
    {
        // Fixed-size input preserves the whole answer with password hashers that
        // have an input byte limit, including long/multibyte answers.
        return hash('sha256', $this->normalize($answer));
    }

    public function update(User|Provider $owner, array $data): array
    {
        Validator::make($data, ['method' => ['required', 'in:email,questions'], 'security_settings_token' => ['required', 'string', 'size:64'],
            'questions' => ['required_if:method,questions', 'array', 'size:3'], 'questions.*.id' => ['required', 'distinct', 'in:'.implode(',', array_keys(self::QUESTIONS))],
            'questions.*.answer' => ['required', 'string', 'min:3', 'max:200']])->validate();
        foreach ($data['questions'] ?? [] as $index => $question) {
            if (mb_strlen($this->normalize($question['answer'])) < 3) {
                throw ValidationException::withMessages(['questions.'.$index.'.answer' => ['Use at least three characters after removing extra spaces.']]);
            }
        }

        return DB::transaction(function () use ($owner, $data) {
            $owner = $owner->newQuery()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $ticket = DB::table('signin_challenges')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
                ->where('token_hash', hash('sha256', $data['security_settings_token']))->lockForUpdate()->first();
            abort_unless($ticket && $ticket->method === 'email' && $ticket->consumed_at && ! $ticket->settings_used_at
                && now()->lt(Carbon::parse($ticket->consumed_at)->addMinutes(10))
                && hash_equals($ticket->email_hash, $this->emailHash($owner)), 403, 'Verify your email again before changing sign-in protection.');
            $answers = [];
            if ($data['method'] === 'questions') {
                foreach ($data['questions'] as $question) {
                    $answers[$question['id']] = Hash::make($this->answerDigest($question['answer']));
                }
            }
            $this->scope($owner)->update(['method' => $data['method'], 'questions' => $answers ? json_encode($answers, JSON_THROW_ON_ERROR) : null, 'configured_at' => now(), 'updated_at' => now()]);
            DB::table('signin_challenges')->where('id', $ticket->id)->update(['settings_used_at' => now()]);
            // Outstanding question challenges must not use the previous answers.
            DB::table('signin_challenges')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return $this->preferences($owner);
        });
    }
}
