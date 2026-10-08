<?php

namespace App\Providers;

use App\Services\SlowQueryLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        DB::listen(fn ($query) => app(SlowQueryLogger::class)($query));
    }
}
