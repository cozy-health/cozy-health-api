<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

$baseUrl = 'http://localhost:8000/api/v1';
$email = 'test@cozyhealth.org';

echo "Running Error Scenario Tests...\n\n";

// --- 5a: Expired Token ---
echo "Test 5a: Expired Token\n";
$expiredToken = '111111';
DB::table('password_reset_tokens')->updateOrInsert(
    ['email' => $email],
    [
        'token' => Hash::make($expiredToken),
        'created_at' => Carbon::now()->subMinutes(20),
        'expires_at' => Carbon::now()->subMinutes(5) // Expired 5 mins ago
    ]
);

$response = Http::post("$baseUrl/reset-password", [
    'email' => $email,
    'token' => $expiredToken,
    'password' => 'NewPass123!'
]);
echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n\n";


// --- 5b: Invalid Token ---
echo "Test 5b: Invalid Token\n";
$response = Http::post("$baseUrl/reset-password", [
    'email' => $email,
    'token' => '999999', // wrong token
    'password' => 'NewPass123!'
]);
echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n\n";


// --- 5c: Email Not Found ---
echo "Test 5c: Email Not Found\n";
$response = Http::post("$baseUrl/forgot-password", [
    'email' => 'nonexistent@email.com'
]);
echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n\n";


// --- 5d: Weak Password ---
echo "Test 5d: Weak Password\n";
$response = Http::post("$baseUrl/reset-password", [
    'email' => $email,
    'token' => '123456',
    'password' => '123' // weak password
]);
echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n\n";

echo "Tests Complete.\n";
