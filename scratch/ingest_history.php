<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Memory;
use App\Models\BusinessService;
use Illuminate\Database\Eloquent\Model;

Model::unguard();

echo "--- MENGINSTAL MEMORI BISNIS RAMLAN ---\n";

// 1. Masukkan Pengetahuan Produk & Harga
BusinessService::updateOrCreate(
    ['name' => 'Kursus Youtube Methods'],
    [
        'base_price' => 5000000,
        'description' => 'Kursus Youtube Private CPA (Online/Offline harga sama). Durasi 3 hari (Jumat-Minggu). Website: youtubeprivatecpa.online',
        'is_active' => true
    ]
);

// 2. Masukkan Memori Fakta Bisnis
$memories = [
    "Harga kursus online dan offline di youtubeprivatecpa.online adalah sama, yaitu Rp 5.000.000.",
    "Durasi kursus offline YouTube Methods adalah 3 hari, biasanya hari Jumat sampai Minggu.",
    "Untuk peserta kursus dari luar kota (seperti Bandung), biasanya perlu menginap karena durasi 3 hari.",
    "Ada program diskon YTM dengan slot terbatas yang sering ditawarkan.",
    "Ramlan sering dipanggil 'Kang' atau 'Hu' oleh para peserta kursus.",
    "Ramlan bisa berkomunikasi menggunakan bahasa Sunda santai (contoh: aya keneh, nuju sakit, hyong ngiringan)."
];

foreach ($memories as $fact) {
    Memory::updateOrCreate(
        ['content' => $fact],
        ['importance' => 5, 'memory_type' => 'fact', 'source' => 'history']
    );
}

echo "✅ Memori Bisnis & Sejarah Berhasil Disuntikkan!\n";
