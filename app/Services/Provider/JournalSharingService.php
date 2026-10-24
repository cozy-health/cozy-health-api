<?php

namespace App\Services\Provider;

use App\Models\{JournalEntry, JournalShare, Provider, ProviderClientLink, ProviderMessage, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JournalSharingService
{
    public function link(User|Provider $actor, string $id): ProviderClientLink
    {
        abort_unless(ctype_digit($id), 404);
        $link = ProviderClientLink::active()->whereKey($id)
            ->where($actor instanceof Provider ? 'provider_id' : 'user_id', $actor->id)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->lockForUpdate()->firstOrFail();
        abort_unless(app(\App\Services\AgeEligibility::class)->allows($link->user), 403, 'Patient records are unavailable.');
        return $link;
    }

    private function entry(ProviderClientLink $link, string $id): JournalEntry
    {
        return JournalEntry::withoutGlobalScope('user')->where('user_id', $link->user_id)
            ->where('is_deleted', false)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function written(JournalEntry $entry): void
    {
        abort_if($entry->is_draft || ! in_array($entry->type, ['free', 'guided', 'text'], true) || filled($entry->audio_attachment_id)
            || filled($entry->voice_url) || blank($entry->body), 422, 'Save and sync a written entry before sharing. Audio sharing is unavailable.');
    }

    private function snapshot(JournalEntry $entry): array
    {
        return ['title' => (string) $entry->title, 'body' => (string) $entry->body];
    }

    private function revision(JournalEntry $entry): string
    {
        return hash('sha256', json_encode($this->snapshot($entry), JSON_THROW_ON_ERROR));
    }

    public function preview(User $actor, string $linkId, string $journalId): array
    {
        return DB::transaction(function () use ($actor, $linkId, $journalId) {
            $link = $this->link($actor, $linkId);
            abort_unless($link->consent_journals, 403, 'Enable journal sharing with this provider first.');
            $entry = $this->entry($link, $journalId);
            $this->written($entry);
            return ['journal_id' => $entry->id, 'revision' => $this->revision($entry),
                'provider' => ['id' => $link->provider_id, 'name' => $link->provider->name],
                'entry' => $this->snapshot($entry), 'later_edits_shared' => false];
        }, 3);
    }

    public function share(User $actor, string $linkId, array $data): array
    {
        return DB::transaction(function () use ($actor, $linkId, $data) {
            $link = $this->link($actor, $linkId);
            abort_unless($link->consent_journals, 403, 'Enable journal sharing with this provider first.');
            $existing = JournalShare::where('provider_client_link_id', $link->id)
                ->where('client_id', $data['client_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->journal_entry_id === $data['journal_id']
                    && hash_equals($existing->source_revision, $data['expected_revision']), 409, 'This confirmation was already used for a different version.');
                abort_if($existing->revoked_at, 409, 'This shared copy was withdrawn. Review again before sharing a new copy.');
                return ['share_id' => $existing->id, 'replayed' => true];
            }
            // The regular message endpoint uses the same client_id namespace.
            abort_if(ProviderMessage::where('provider_client_link_id', $link->id)->where('sender', 'patient')
                ->where('client_id', $data['client_id'])->exists(), 409, 'This confirmation was already used.');
            $entry = $this->entry($link, $data['journal_id']);
            $this->written($entry);
            abort_unless(hash_equals($this->revision($entry), $data['expected_revision']), 409, 'The entry changed. Review it again before sharing.');
            $share = JournalShare::create(['id' => (string) Str::uuid(), 'user_id' => $actor->id,
                'provider_client_link_id' => $link->id, 'journal_entry_id' => $entry->id,
                'client_id' => $data['client_id'], 'source_revision' => $data['expected_revision'],
                'snapshot' => $this->snapshot($entry)]);
            ProviderMessage::create(['provider_client_link_id' => $link->id, 'sender' => 'patient',
                'client_id' => $data['client_id'], 'content' => '',
                'records' => [['type' => 'journal_shares', 'id' => $share->id]]]);
            ProviderAudit::record(request(), $link, 'shared', 'journal_share', $share->id);
            return ['share_id' => $share->id, 'replayed' => false];
        }, 3);
    }

    private function visible(ProviderClientLink $link, JournalShare $share): bool
    {
        return $link->consent_journals && ! $link->revoked_at && $link->verified_at
            && (int) $share->provider_client_link_id === (int) $link->id
            && (int) $share->user_id === (int) $link->user_id && ! $share->revoked_at
            && $share->getRawOriginal('snapshot') !== null
            && JournalEntry::withoutGlobalScope('user')->whereKey($share->journal_entry_id)
                ->where('user_id', $link->user_id)->where('is_deleted', false)->exists();
    }

    public function read(User|Provider $actor, string $linkId, string $shareId): array
    {
        return DB::transaction(function () use ($actor, $linkId, $shareId) {
            $link = $this->link($actor, $linkId);
            $share = JournalShare::where('provider_client_link_id', $link->id)->whereKey($shareId)->lockForUpdate()->firstOrFail();
            abort_unless($this->visible($link, $share), 404);
            if ($actor instanceof Provider) ProviderAudit::record(request(), $link, 'view', 'journal_share', $share->id);
            return ['share_id' => $share->id, 'entry' => $share->snapshot,
                'shared_at' => $share->created_at->toIso8601String(), 'later_edits_shared' => false];
        }, 3);
    }

    /** Safe for existing clients which durably cache conversation messages: no text. */
    public function reference(ProviderClientLink $link, string $shareId): array
    {
        $base = ['type' => 'journal_shares', 'id' => $shareId];
        $share = JournalShare::whereKey($shareId)->first();
        if (! $share || ! $this->visible($link, $share)) return $base + ['unavailable' => true];
        return $base + ['data' => ['requires_online_review' => true, 'label' => 'Shared written journal entry']];
    }

    public function revoke(User $actor, string $linkId, string $shareId): void
    {
        abort_unless(ctype_digit($linkId), 404);
        DB::transaction(function () use ($actor, $linkId, $shareId) {
            // Withdrawal remains available after category consent has been disabled.
            $link = ProviderClientLink::where('user_id', $actor->id)->whereKey($linkId)->lockForUpdate()->firstOrFail();
            $share = JournalShare::where('user_id', $actor->id)->where('provider_client_link_id', $link->id)
                ->whereKey($shareId)->lockForUpdate()->firstOrFail();
            if (! $share->revoked_at) ProviderAudit::record(request(), $link, 'share_withdrawn', 'journal_share', $share->id);
            $this->purge(JournalShare::whereKey($share->id));
        }, 3);
    }

    public function purge(Builder $query): void
    {
        $query->whereNull('revoked_at')->update(['snapshot' => null, 'revoked_at' => now(), 'updated_at' => now()]);
    }
}
