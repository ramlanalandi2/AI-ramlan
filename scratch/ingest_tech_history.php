<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Memory;
use Illuminate\Database\Eloquent\Model;

Model::unguard();

echo "--- MENGINSTAL MEMORI TEKNIS RAMLAN ---\n";

$techMemories = [
    "Fanpage Facebook tidak memiliki izin untuk memulai chat (direct message) ke profil personal tanpa interaksi/history sebelumnya. Tombol 'Message' akan hilang jika dibuka sebagai Fanpage.",
    "Fitur Auto DM atau Blast Messenger hanya bisa bekerja secara teknis pada akun Personal Profile, bukan Fanpage (kecuali sudah ada history chat).",
    "Ramlan sedang/pernah mengembangkan fitur Blast Messenger menggunakan Cursor dan Ollama.",
    "Harga jasa pembuatan fitur otomatisasi khusus (seperti auto DM) berkisar antara Rp 500.000 sampai Rp 1.000.000.",
    "Ramlan tidak menggunakan saldo PayPal (PP) untuk transaksi saat ini.",
    "Kesimpulan teknis workflow auto DM: Berhasil untuk personal profile, tapi gagal untuk Fanpage jika ingin chat duluan karena pembatasan tombol Messenger dari Facebook."
];

foreach ($techMemories as $fact) {
    Memory::updateOrCreate(
        ['content' => $fact],
        ['importance' => 5, 'memory_type' => 'fact', 'source' => 'technical_research']
    );
}

echo "✅ Pengetahuan Teknis & Batasan Sistem Berhasil Disuntikkan!\n";
