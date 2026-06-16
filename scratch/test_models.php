<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\AiReplyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new class extends AiReplyService {
    public function testGetModels() {
        return $this->getAvailableFreeModels();
    }
    
    // Override visibility for test
    protected function getAvailableFreeModels(): array {
        return parent::getAvailableFreeModels();
    }
};

echo "--- MENGAMBIL DAFTAR MODEL GRATIS ---" . PHP_EOL;
$models = $service->testGetModels();

if (empty($models)) {
    echo "GAGAL: Daftar model kosong atau terjadi error." . PHP_EOL;
} else {
    echo "BERHASIL: Ditemukan " . count($models) . " model gratis." . PHP_EOL;
    echo "Contoh 5 model pertama (setelah diacak):" . PHP_EOL;
    foreach (array_slice($models, 0, 5) as $m) {
        echo "- " . $m . PHP_EOL;
    }
}
