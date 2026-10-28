<?php

namespace App\Services;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;

final class StoreVerificationLock
{
    public static function forUser(int $userId): Lock
    {
        // Shared across API and worker, independent of CACHE_PREFIX.
        $store = new DatabaseStore(DB::connection(), 'cache', 'cozy-store-verification:', 'cache_locks');
        return $store->lock((string) $userId, 120);
    }
}
