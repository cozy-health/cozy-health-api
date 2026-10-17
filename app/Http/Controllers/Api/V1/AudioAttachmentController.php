<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AudioAttachment;
use App\Models\JournalEntry;
use App\Models\Provider;
use App\Models\ProviderMessage;
use App\Services\AudioAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioAttachmentController extends ApiController
{
    public function store(Request $request, AudioAttachmentService $service)
    {
        $data = $request->validate([
            'client_id' => 'required|uuid', 'purpose' => 'required|in:journal,message',
            'provider_client_link_id' => 'required_if:purpose,message|nullable|integer',
            'duration' => 'required|integer|min:1|max:300',
            'audio' => 'required|file|max:20480|mimetypes:audio/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/webm,video/webm,audio/ogg,application/ogg,video/mp4',
        ]);
        $actor = $request->user();
        abort_if($actor instanceof Provider && $data['purpose'] !== 'message', 403);
        if ($data['purpose'] === 'message') $service->link($actor, $data['provider_client_link_id']);
        else abort_if(! empty($data['provider_client_link_id']), 422);

        return DB::transaction(function () use ($actor, $data, $request) {
            // Serialize retries for this owner, including concurrent uploads.
            $actor->newQuery()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $ownerType = $actor instanceof Provider ? 'provider' : 'patient';
            $existing = AudioAttachment::where('owner_type', $ownerType)->where('owner_id', $actor->id)->where('client_id', $data['client_id'])->first();
            if ($existing) {
                abort_unless($existing->purpose === $data['purpose'] && (string) $existing->provider_client_link_id === (string) ($data['provider_client_link_id'] ?? null), 422);
                return response()->json($existing);
            }
            $id = (string) Str::uuid();
            $disk = config('filesystems.audio_disk', 'local');
            abort_if($disk === 'public' || config('filesystems.disks.'.$disk.'.visibility') === 'public', 503, 'Private audio storage is not configured.');
            $file = $request->file('audio');
            $converted = app(\App\Services\AudioTranscoder::class)->convert($file);
            $key = 'audio/'.$ownerType.'/'.$actor->id.'/'.$id.'.m4a';
            try {
                $stream = fopen($converted['path'], 'rb');
                try {
                    abort_unless(Storage::disk($disk)->put($key, $stream, ['visibility' => 'private']), 503, 'Unable to save recording. Please retry.');
                } finally { if (is_resource($stream)) fclose($stream); }
                $audio = AudioAttachment::create([
                    'id' => $id, 'owner_type' => $ownerType, 'owner_id' => $actor->id,
                    'client_id' => $data['client_id'], 'purpose' => $data['purpose'],
                    'provider_client_link_id' => $data['provider_client_link_id'] ?? null,
                    'disk' => $disk, 'storage_key' => $key, 'media_type' => 'audio/mp4',
                    'byte_size' => filesize($converted['path']), 'duration' => $converted['duration'],
                ]);
            } catch (\Throwable $e) {
                Storage::disk($disk)->delete($key);
                throw $e;
            } finally { @unlink($converted['path']); }
            return response()->json($audio, 201);
        });
    }

    public function show(Request $request, string $id, AudioAttachmentService $service)
    {
        $audio = AudioAttachment::findOrFail($id);
        $actor = $request->user();
        if ($audio->purpose === 'journal') {
            abort_unless($audio->ownedBy($actor), 404);
            abort_unless(JournalEntry::withoutGlobalScopes()->where('user_id', $actor->id)->where('audio_attachment_id', $id)->whereNull('deleted_at')->where('is_deleted', false)->exists(), 404);
        } else {
            $service->link($actor, $audio->provider_client_link_id);
            abort_unless($audio->ownedBy($actor) || ProviderMessage::where('provider_client_link_id', $audio->provider_client_link_id)->where('audio_attachment_id', $id)->exists(), 404);
        }
        $disk = Storage::disk($audio->disk);
        abort_unless($disk->exists($audio->storage_key), 404);
        $content = $disk->get($audio->storage_key);
        $size = strlen($content);
        $headers = [
            'Content-Type' => $audio->media_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Accept-Ranges' => 'bytes',
        ];
        $range = $request->header('Range');
        if ($range !== null) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $parts) || ($parts[1] === '' && $parts[2] === '') || $size === 0) {
                return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            $start = $parts[1] === '' ? max(0, $size - (int) $parts[2]) : (int) $parts[1];
            $end = $parts[1] === '' || $parts[2] === '' ? $size - 1 : min($size - 1, (int) $parts[2]);
            if ($start >= $size || $end < $start) return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            return response(substr($content, $start, $end - $start + 1), 206, $headers + [
                'Content-Range' => "bytes $start-$end/$size", 'Content-Length' => $end - $start + 1,
            ]);
        }
        return response($content, 200, $headers + ['Content-Length' => $size]);
    }
}
