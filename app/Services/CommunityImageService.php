<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityImageService
{
    public function upload(UploadedFile $file): string
    {
        abort_unless(function_exists('imagecreatefromstring'), 503, 'Image processing is temporarily unavailable.');
        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] > 4095 || $info[1] > 4095 ||
            ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['image' => 'Choose a JPEG, PNG or WebP image up to 4095 pixels on each side.']);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['image' => 'This image could not be read.']);
        }
        $scale = min(1, 1280 / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($image);
        // Fresh encoding strips EXIF/GPS and any appended input data. Random
        // keys contain no account identity, including for anonymous authors.
        $key = 'community/'.Str::uuid().'.jpg';
        try {
            if (! Storage::disk(config('avatars.disk'))->put($key, $bytes, ['visibility' => 'private', 'ContentType' => 'image/jpeg'])) {
                throw new \RuntimeException('Image storage unavailable');
            }
        } catch (\Throwable) {
            $this->discard($key);
            abort(503, 'Image storage is temporarily unavailable. Your post was not changed.');
        }

        return $key;
    }

    public function url(?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        try {
            return Storage::disk(config('avatars.disk'))->temporaryUrl($key, now()->addMinutes(5));
        } catch (\Throwable) {
            return null;
        }
    }

    public function discard(?string $key): void
    {
        if ($key) {
            ProfileAvatarService::queueDeletion(config('avatars.disk'), $key);
        }
    }
}
