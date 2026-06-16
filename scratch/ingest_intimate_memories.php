<?php

use App\Models\Contact;
use App\Models\Memory;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$contact = Contact::where('fb_profile_url', 'https://www.facebook.com/shantidhewii')->first();

if (!$contact) {
    echo "Kontak Bunda tidak ditemukan!\n";
    exit;
}

$newMemories = [
    "Bunda dan Ayah punya inside joke soal 'koleksi yang banyak' untuk disiapkan setiap Ayah pulang.",
    "Ada candaan rutin soal 'ngewong setiap hari' sebagai cara agar Bunda tidak uring-uringan atau pusing.",
    "Ayah dan Bunda sering bercanda soal memindahkan tempat tidur anak atau 'mengusir' anak sebentar agar bisa berduaan (privasi).",
    "Bunda pernah mengeluh pusing soal biaya WiFi, dan Ayah menanggapinya dengan santai/menggoda.",
    "Ayah sangat suka kalau Bunda menyiapkan koleksi yang banyak untuk momen intim mereka.",
    "Gaya bicara Ayah ke Bunda sangat santai, sering pakai bahasa Sunda tipis-tipis, dan selalu punya arah menggoda (high tension)."
];

foreach ($newMemories as $content) {
    Memory::create([
        'contact_id' => $contact->id,
        'memory_type' => 'relationship',
        'source' => 'manual',
        'content' => $content,
        'importance' => 5
    ]);
}

echo "Memori Intim Ayah-Bunda berhasil di-ingest! 🧠🔥✅\n";
