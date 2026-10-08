<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('user:make-admin {email}', function () {
    $user = \App\Models\User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('No active user found for that email.');
        return 1;
    }
    $user->forceFill(['role' => 'admin'])->save();
    $this->info('Admin role assigned.');
    return 0;
})->purpose('Grant an existing active user the administrator role');
