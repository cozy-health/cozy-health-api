<?php

namespace App\Services\Provider;

use App\Mail\ProviderInvitationMail;
use App\Models\AppNotification;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use App\Models\User;
use App\Services\PlanAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ProviderReplacementService
{
    public function state(User $user, ?ProviderClientLink $original): array
    {
        $pending = $original ? ProviderClientLink::where('user_id', $user->id)
            ->where('replacement_of_link_id', $original->id)->orderByDesc('replacement_started_at')->orderByDesc('id')->first() : null;
        $reply = $original ? ProviderMessage::where('provider_client_link_id', $original->id)->where('sender', 'provider')
            ->orderByDesc('created_at')->orderByDesc('id')->first() : null;
        $first = $original ? ProviderMessage::where('provider_client_link_id', $original->id)->where('sender', 'patient')
            ->when($reply, fn ($q) => $q->where(fn ($after) => $after->where('created_at', '>', $reply->created_at)
                ->orWhere(fn ($tie) => $tie->where('created_at', $reply->created_at)->where('id', '>', $reply->id))))
            ->orderBy('created_at')->orderBy('id')->first() : null;
        $at = $first?->created_at->copy()->addHours(168);
        $active = $original && $original->verified_at && ! $original->revoked_at;
        $waiting = $original && ProviderClientLink::where('user_id', $user->id)->where('replacement_of_link_id', $original->id)
            ->where('replacement_status', 'pending')->whereNull('revoked_at')->where('verification_expires_at', '>', now())->exists();

        return ['eligible' => (bool) ($active && $at?->lte(now()) && ! $waiting && app(PlanAccess::class)->allows($user, 'provider_connect')),
            'qualifying_message_id' => $first?->id, 'eligible_at' => $at?->toIso8601String(),
            'pending' => $pending ? $this->payload($pending) : null];
    }

    public function payload(ProviderClientLink $link): array
    {
        $status = $link->replacement_status;
        if ($status === 'pending' && $link->verification_expires_at?->lte(now())) {
            $status = 'expired';
        }

        return ['id' => $link->id, 'request_id' => $link->replacement_request_id, 'original_link_id' => $link->replacement_of_link_id,
            'provider_email' => $link->provider_email, 'status' => $status,
            'expires_at' => $link->verification_expires_at?->toIso8601String(),
            'invitation_code' => $status === 'pending' ? $link->invitation_code_secret : null,
            'invitation_portal_url' => config('services.provider.portal_url')];
    }

    public function start(Request $request, array $data): array
    {
        return DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            app(PlanAccess::class)->require($user, 'provider_connect');
            $email = strtolower(trim($data['provider_email']));
            $receipt = DB::table('provider_replacement_requests')->where('id', $data['request_id'])->first();
            $retry = ProviderClientLink::where('replacement_request_id', $data['request_id'])->lockForUpdate()->first();
            abort_if($receipt && ! $retry, 409, 'This replacement request has been superseded. Refresh your connection.');
            if ($retry) {
                abort_unless((int) $retry->user_id === (int) $user->id && $retry->provider_email === $email
                    && (int) $retry->replacement_of_link_id === (int) $data['original_link_id'], 409, 'This request identifier was already used.');

                return [$retry, false];
            }
            $original = ProviderClientLink::where('user_id', $user->id)->active()->whereKey($data['original_link_id'])->lockForUpdate()->firstOrFail();
            $state = $this->state($user, $original);
            abort_unless($state['eligible'], 409, 'A replacement is available after seven unanswered days, with no other invitation pending.');
            $provider = Provider::whereRaw('LOWER(email) = ?', [$email])->first();
            abort_if($email === strtolower($original->provider_email) || ($provider && (int) $provider->id === (int) $original->provider_id), 422, 'Choose a different provider.');
            abort_if($provider && ! $provider->is_active, 422, 'This provider is unavailable.');
            abort_if(ProviderClientLink::where('user_id', $user->id)->where('provider_email', $email)->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNotNull('verified_at')->orWhere('verification_expires_at', '>', now()))->exists(), 409, 'An active connection or invitation already exists for this provider.');
            $code = (string) random_int(100000, 999999);
            $link = ProviderClientLink::where('user_id', $user->id)->where('provider_email', $email)->lockForUpdate()->first()
                ?? new ProviderClientLink(['user_id' => $user->id]);
            $link->fill(['provider_id' => $provider?->id, 'provider_email' => $email,
                'provider_name' => $provider?->name, 'verification_actor' => 'provider', 'verification_code' => Hash::make($code),
                'invitation_code_secret' => $code, 'invitation_code_lookup' => InvitationCode::lookup($email, $code),
                'invitation_sent_at' => now(), 'verification_expires_at' => now()->addMinutes(15), 'verified_at' => null,
                'revoked_at' => null, 'provider_acknowledged_at' => null,
                'consent_moods' => false, 'consent_journals' => false, 'consent_quizzes' => false, 'consent_notes' => false]);
            $link->forceFill(['replacement_of_link_id' => $original->id, 'replacement_request_id' => $data['request_id'],
                'replacement_qualifying_message_id' => $state['qualifying_message_id'],
                'replacement_started_at' => now(), 'replacement_status' => 'pending'])->save();
            DB::table('provider_replacement_requests')->insert(['id' => $data['request_id'], 'user_id' => $user->id, 'link_id' => $link->id]);
            Mail::to($email)->queue(new ProviderInvitationMail($link->id, $code));
            if ($original->provider_id) {
                ProviderAudit::record($request, $original, 'replacement_requested', 'client', (string) $link->id);
            }

            return [$link, true];
        }, 3);
    }

    public function cancel(Request $request, string $id): void
    {
        DB::transaction(function () use ($request, $id) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('provider_replacement_requests')->where('user_id', $request->user()->id)->where('id', $id)->first();
            abort_unless($receipt, 404);
            $link = ProviderClientLink::where('user_id', $request->user()->id)->whereKey($receipt->link_id)->lockForUpdate()->firstOrFail();
            abort_unless($link->replacement_request_id === $id, 409, 'This replacement request was superseded. Refresh your connection.');
            abort_if($link->verified_at, 409, 'This provider has already accepted. Refresh your connections.');
            if (! $link->revoked_at) {
                $link->forceFill(['replacement_status' => 'cancelled', 'revoked_at' => now(), 'verification_code' => null,
                    'invitation_code_lookup' => null, 'verification_expires_at' => null])->save();
            }
        }, 3);
    }

    public function accept(Request $request, ProviderClientLink $link): void
    {
        if (! $link->replacement_of_link_id) {
            return;
        }
        abort_unless($link->replacement_status === 'pending', 409, 'This replacement is no longer pending.');
        $original = ProviderClientLink::where('user_id', $link->user_id)->active()->whereKey($link->replacement_of_link_id)->lockForUpdate()->first();
        abort_unless($original, 409, 'The original connection changed. Ask the patient to refresh.');
        $link->forceFill(['replacement_status' => 'accepted'])->save();
        DB::table('quiz_shares')->where('link_id', $original->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        $original->update(['revoked_at' => now(), 'consent_moods' => false, 'consent_journals' => false, 'consent_quizzes' => false, 'consent_notes' => false]);
        foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) {
            $link->{'consent_'.$type} = false;
        }
        ProviderAudit::record($request, $original, 'replacement_completed', 'client', (string) $link->id);
        AppNotification::create(['id' => (string) Str::uuid(), 'user_id' => $link->user_id, 'type' => 'provider',
            'title' => 'Provider connection updated', 'body' => 'Your replacement provider accepted. Review your sharing choices.',
            'deep_link' => '/settings/provider', 'data' => ['link_id' => $link->id], 'is_read' => false]);
    }
}
