<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = 'http://localhost:8000/api/v1';

echo "Testing Register Endpoint + Welcome Email...\n\n";

$response = Http::post("$baseUrl/register", [
    'name' => 'New Member',
    'email' => 'test-welcome-' . time() . '@cozyhealth.org',
    'password' => 'password123',
    'password_confirmation' => 'password123'
]);

echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n\n";
