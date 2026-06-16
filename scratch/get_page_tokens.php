<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$token = env('FACEBOOK_PAGE_ACCESS_TOKEN');

$response = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/v20.0/me/accounts", [
    'access_token' => $token,
]);

if ($response->successful()) {
    $data = $response->json('data');
    echo "PAGES FOUND: " . count($data) . "\n\n";
    foreach ($data as $page) {
        echo "NAME: " . $page['name'] . "\n";
        echo "ID: " . $page['id'] . "\n";
        echo "PAGE_TOKEN: " . $page['access_token'] . "\n";
        echo "---------------------------\n";
    }
} else {
    echo "FAILED TO GET ACCOUNTS\n";
    echo $response->body() . "\n";
}
