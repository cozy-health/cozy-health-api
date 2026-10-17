<?php

namespace App\Services;

use App\Models\AudioAttachment;
use App\Models\JournalEntry;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AudioAttachmentService
{
    public function link($actor, $id): ProviderClientLink
    {
        return ProviderClientLink::active()->whereNotNull('provider_id')->whereHas('user')
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->where($actor instanceof Provider ? 'provider_id' : 'user_id', $actor->id)->findOrFail($id);
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
        AudioAttachment::where('created_at', '<', now()->subDays(7))->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $audio) {
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
