<?php

use Illuminate\Support\Facades\Route;

Route::get('/up', \App\Http\Controllers\HealthController::class)->withoutMiddleware('web');
