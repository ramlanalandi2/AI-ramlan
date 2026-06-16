<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$key = env('OPENROUTER_API_KEY');
echo "API Key Length: " . strlen($key) . "\n";
echo "API Key Preview: " . substr($key, 0, 10) . "...\n";

$models = [
    'google/gemini-flash-1.5-8b',
    'google/gemini-2.0-flash-exp:free',
    'google/gemini-2.0-flash-exp',
    'meta-llama/llama-3.1-8b-instruct',
    'openai/gpt-3.5-turbo',
];

foreach ($models as $model) {
    echo "Testing Model: {$model}\n";
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $key,
        'Content-Type' => 'application/json',
    ])->post('https://openrouter.ai/api/v1/chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]);

    echo "Status: " . $response->status() . "\n";
    if ($response->successful()) {
        echo "✅ SUCCESS!\n\n";
        break;
    } else {
        echo "❌ FAILED: " . $response->body() . "\n\n";
    }
}
