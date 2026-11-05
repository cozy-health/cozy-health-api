<?php

namespace App\Services;

use App\Models\AudioAttachment;
use App\Models\JournalEntry;
use App\Models\JournalShare;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AudioAttachmentService
{
    public function link($actor, $id): ProviderClientLink
    {
        $link = ProviderClientLink::active()->whereNotNull('provider_id')->whereHas('user')
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->where($actor instanceof Provider ? 'provider_id' : 'user_id', $actor->id)->findOrFail($id);
        abort_unless(app(AgeEligibility::class)->allows($link->user), 403, 'This recording relationship is unavailable.');
        return $link;
    }

    public function reference($actor, string $id, string $purpose, $linkId = null): AudioAttachment
    {
        $audio = AudioAttachment::find($id);
        if (! $audio || ! $audio->ownedBy($actor) || $audio->purpose !== $purpose
            || (string) $audio->provider_client_link_id !== (string) $linkId) {
            throw ValidationException::withMessages(['audio_attachment_id' => ['This recording is unavailable.']]);
        }
        return $audio;
    }

    public function cleanup(): int
    {
        $count = 0;
        $sharedAudio = [];
        foreach (JournalShare::whereNull('revoked_at')->whereNotNull('snapshot')->cursor() as $share) {
            $id = $share->snapshot['audio']['id'] ?? null;
            if ($id && ProviderClientLink::active()->whereKey($share->provider_client_link_id)->where('consent_journals', true)->exists()
                && JournalEntry::withoutGlobalScopes()->whereKey($share->journal_entry_id)->where('is_deleted', false)->whereNull('deleted_at')->exists()) {
                $sharedAudio[$id] = true;
            }
        }
        AudioAttachment::where('created_at', '<', now()->subDays(7))->chunkById(100, function ($rows) use (&$count, $sharedAudio) {
            foreach ($rows as $audio) {
                if (isset($sharedAudio[$audio->id])) continue;
                if (JournalEntry::withoutGlobalScopes()->where('audio_attachment_id', $audio->id)->whereNull('deleted_at')->where('is_deleted', false)->exists()
                    || ProviderMessage::where('audio_attachment_id', $audio->id)->exists()) continue;
                if (Storage::disk($audio->disk)->delete($audio->storage_key)) {
                    $audio->delete();
                    $count++;
                }
            }
        });
        return $count;
    }
}
