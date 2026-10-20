<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupAvatarPhotos extends Command
{
    protected $signature = 'avatars:cleanup';

    protected $description = 'Retry durable cleanup of replaced and removed profile photos';

    public function handle(): int
    {
        foreach (DB::table('avatar_photo_deletions')->orderBy('id')->limit(100)->get() as $item) {
            try {
                if (! Storage::disk($item->disk)->delete($item->object_key)) {
                    throw new \RuntimeException('Delete failed');
                }
                DB::table('avatar_photo_deletions')->where('id', $item->id)->delete();
            } catch (\Throwable) {
                DB::table('avatar_photo_deletions')->where('id', $item->id)->increment('attempts');
            }
        }

        return self::SUCCESS;
    }
}
