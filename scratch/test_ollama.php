<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\AiReplyService;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new class extends AiReplyService {
    public function testOllama($msg) {
        $messages = [
            ['role' => 'system', 'content' => 'Kamu adalah RAMLAN. Jawab singkat dan natural.'],
            ['role' => 'user', 'content' => $msg]
        ];
        return $this->callOllamaApi($messages);
    }
    
    protected function callOllamaApi(array $messages) {
        return parent::callOllamaApi($messages);
    }
};

echo "--- MENGETEST OLLAMA LOKAL (qwen2.5:7b) ---" . PHP_EOL;
$testMsg = "Halo Ayah, apa kabar hari ini?";
echo "Pesan User: $testMsg" . PHP_EOL;
echo "Memanggil Ollama... (Mungkin butuh waktu untuk load model)" . PHP_EOL;

$startTime = microtime(true);
$reply = $service->testOllama($testMsg);
$endTime = microtime(true);

$duration = round($endTime - $startTime, 2);

echo PHP_EOL . "--- HASIL OLLAMA ---" . PHP_EOL;
echo "Waktu Proses: $duration detik" . PHP_EOL;
echo "Respond: " . ($reply ?? "GAGAL (Pastikan Ollama sudah running)") . PHP_EOL;
echo "----------------------" . PHP_EOL;
