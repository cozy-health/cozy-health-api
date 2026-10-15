<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CheckAvatarStorage extends Command
{
    protected $signature = 'avatars:check-storage';

    protected $description = 'Verify private avatar storage with a temporary object and signed download';

    public function handle(): int
    {
        $key = 'verification/avatar-'.Str::uuid().'.txt';
        $disk = null;
        try {
            $disk = Storage::disk(config('avatars.disk'));
            if (! $disk->put($key, 'cozy-avatar-check', ['visibility' => 'private', 'ContentType' => 'text/plain'])) {
                throw new \RuntimeException;
            }
            if ($disk->get($key) !== 'cozy-avatar-check') {
                throw new \RuntimeException;
            }
            $url = $disk->temporaryUrl($key, now()->addMinutes(2));
            $response = Http::timeout(15)->get($url);
            if (! $response->successful() || $response->body() !== 'cozy-avatar-check') {
                throw new \RuntimeException;
            }
            $unsigned = Http::timeout(15)->get(explode('?', $url)[0]);
            if ($unsigned->successful()) {
                throw new \RuntimeException;
            }
            if (! $disk->delete($key)) {
                throw new \RuntimeException;
            }
            $this->info('Private avatar storage write, read, signed download, and cleanup passed.');

            return self::SUCCESS;
        } catch (\Throwable) {
            try {
                $disk?->delete($key);
            } catch (\Throwable) {
            }
            $this->error('Avatar storage verification failed. Check the private bucket configuration. No credentials printed.');

            return self::FAILURE;
        }
    }
}
