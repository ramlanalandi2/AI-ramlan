<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Memory;
use Illuminate\Database\Eloquent\Model;

Model::unguard();

echo "--- DIAGNOSA INSERT MEMORY ---\n";

try {
    $m = new Memory();
    $m->content = "Tes Memori " . time();
    $m->importance = 5;
    $m->memory_type = 'fact';
    $m->source = 'manual';
    $m->save();
    
    echo "✅ Berhasil simpan! ID: " . $m->id . "\n";
} catch (\Exception $e) {
    echo "❌ GAGAL SIMPAN!\n";
    echo "Pesan Error: " . $e->getMessage() . "\n";
}
