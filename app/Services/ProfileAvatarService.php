<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileAvatarService
{
    public static function validPreset(string $id): bool
    {
        return preg_match('/^open-peeps-(0[1-9]|1[0-9]|2[0-4])$/D', $id) === 1;
    }

    public function url(UserProfile $profile, ?string $legacy): ?string
    {
        if ($profile->avatar_preset_id) {
            return 'asset:'.$profile->avatar_preset_id;
        }
        if (! $profile->avatar_object_key) {
            return $legacy ?: null;
        }
        try {
            return Storage::disk(config('avatars.disk'))->temporaryUrl($profile->avatar_object_key, now()->addMinutes(config('avatars.url_minutes')));
        } catch (\Throwable) {
            // Storage downtime must not prevent loading the rest of the profile.
            return null;
        }
    }

    public function upload(User $user, UploadedFile $file): UserProfile
    {
        abort_unless(function_exists('imagecreatefromstring'), 503, 'Photo processing is unavailable. Please try again later.');
        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] > 4096 || $info[1] > 4096 || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw ValidationException::withMessages(['photo' => ['Choose a JPEG or PNG image no larger than 4096 × 4096 pixels.']]);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['photo' => ['The image could not be read.']]);
        }
        $image = imagecreatetruecolor(512, 512);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $side = min(imagesx($source), imagesy($source));
        imagecopyresampled($image, $source, 0, 0, (int) ((imagesx($source) - $side) / 2), (int) ((imagesy($source) - $side) / 2), 512, 512, $side, $side);
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($image);
        $key = 'avatars/'.$user->id.'/'.Str::uuid().'.jpg';
        $disk = config('avatars.disk');
        try {
            if (! Storage::disk($disk)->put($key, $bytes, ['ContentType' => 'image/jpeg', 'visibility' => 'private'])) {
                throw new \RuntimeException('Write failed');
            }
        } catch (\Throwable) {
            abort(503, 'Photo storage is unavailable. Your current picture has not changed.');
        }
        try {
            return $this->replace($user, null, $key);
        } catch (\Throwable $error) {
            try {
                if (! Storage::disk($disk)->delete($key)) {
                    self::queueDeletion($disk, $key);
                }
            } catch (\Throwable) {
                try {
                    self::queueDeletion($disk, $key);
                } catch (\Throwable) {
                    Log::warning('Avatar rollback cleanup could not be scheduled.');
                }
            }
            throw $error;
        }
    }

    public function replace(User $user, ?string $preset, ?string $key = null): UserProfile
    {
        if ($preset !== null && ! self::validPreset($preset)) {
            throw ValidationException::withMessages(['preset_id' => ['Choose one of the available avatars.']]);
        }

        return DB::transaction(function () use ($user, $preset, $key) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $user->profile()->firstOrCreate([], ['id' => (string) Str::uuid(), 'name' => $user->name, 'email' => $user->email]);
            $old = $profile->avatar_object_key;
            $profile->forceFill(['avatar_url' => null, 'avatar_preset_id' => $preset, 'avatar_object_key' => $key, 'avatar_revision' => (int) $profile->avatar_revision + 1])->save();
            if ($old && $old !== $key) {
                self::queueDeletion(config('avatars.disk'), $old);
            }

            return $profile;
        });
    }

    public static function queueDeletion(string $disk, string $key): void
    {
        DB::table('avatar_photo_deletions')->insert(['disk' => $disk, 'object_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }
}
