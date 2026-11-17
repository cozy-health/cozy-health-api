<?php

namespace App\Services;

use App\Models\AudioAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrivateAudioResponse
{
    /** Authorization belongs to the caller; this handles private bytes and ranges. */
    public static function make(Request $request, AudioAttachment $audio)
    {
        $disk = Storage::disk($audio->disk);
        abort_unless($disk->exists($audio->storage_key), 404);
        $content = $disk->get($audio->storage_key);
        $size = strlen($content);
        $headers = ['Content-Type' => $audio->media_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Accept-Ranges' => 'bytes'];
        $range = $request->header('Range');
        if ($range !== null) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $parts) || ($parts[1] === '' && $parts[2] === '') || $size === 0) {
                return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            $start = $parts[1] === '' ? max(0, $size - (int) $parts[2]) : (int) $parts[1];
            $end = $parts[1] === '' || $parts[2] === '' ? $size - 1 : min($size - 1, (int) $parts[2]);
            if ($start >= $size || $end < $start) return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            return response(substr($content, $start, $end - $start + 1), 206, $headers + [
                'Content-Range' => "bytes $start-$end/$size", 'Content-Length' => $end - $start + 1]);
        }
        return response($content, 200, $headers + ['Content-Length' => $size]);
    }
}
