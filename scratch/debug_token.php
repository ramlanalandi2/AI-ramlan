<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$token = env('FACEBOOK_PAGE_ACCESS_TOKEN');

$response = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/v20.0/debug_token", [
    'input_token' => $token,
    'access_token' => $token, // Using same token for debug if it's long-lived
]);

echo "TOKEN DEBUG:\n";
echo $response->body() . "\n";
