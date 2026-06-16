<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$token = env('FACEBOOK_PAGE_ACCESS_TOKEN');

if (!$token) {
    echo "Token not found in .env\n";
    exit;
}

$response = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/v20.0/me", [
    'access_token' => $token,
    'fields' => 'id,name'
]);

if ($response->successful()) {
    echo "SUCCESS\n";
    echo "ID: " . $response->json('id') . "\n";
    echo "NAME: " . $response->json('name') . "\n";
} else {
    echo "FAILED\n";
    echo $response->body() . "\n";
}
