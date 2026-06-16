<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\AiReplyService;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new AiReplyService();

$testName = "Resti Dewi";
$testMessage = "Ayah, jangan lupa makan ya!";
$testUrl = "https://www.facebook.com/messages/t/100014031167436/";

echo "--- MENCOBA MENGIRIM PESAN KE AI ---" . PHP_EOL;
echo "Lawan Bicara: $testName" . PHP_EOL;
echo "Pesan: $testMessage" . PHP_EOL;
echo "Sedang memproses (Mungkin butuh waktu karena retrial)..." . PHP_EOL;

$startTime = microtime(true);
$result = $service->handle("TEST_PHONE", $testName, $testMessage, $testUrl);
$endTime = microtime(true);

$duration = round($endTime - $startTime, 2);

echo PHP_EOL . "--- HASIL RESPOND ---" . PHP_EOL;
echo "Waktu Proses: $duration detik" . PHP_EOL;
echo "Respond: " . $result['ai_reply'] . PHP_EOL;
echo "Model Used: " . ($result['model_used'] ?? 'unknown') . PHP_EOL;
echo "----------------------" . PHP_EOL;

if (in_array($result['model_used'] ?? '', ['fallback_humanizer', 'system_fallback', 'none', 'unknown'])) {
    echo "STATUS: FALLBACK (Gagal dapat AI asli) ❌" . PHP_EOL;
} else {
    echo "STATUS: SUKSES (Dapat jawaban AI asli dari " . $result['model_used'] . ") ✅" . PHP_EOL;
}
