<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$email = 'bakaretioluwani@yahoo.com';
$existing = \App\Models\User::where('email', $email)->first();

if ($existing) {
    echo "User already exists: {$existing->email}\n";
} else {
    $user = \App\Models\User::create([
        'name' => 'Tioluwani Bakare',
        'email' => $email,
        'password' => \Illuminate\Support\Facades\Hash::make('password123'),
    ]);
    echo "User created: {$user->email}\n";
}
