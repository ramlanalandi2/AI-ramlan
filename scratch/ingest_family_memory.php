<?php

use App\Models\Contact;
use App\Models\Memory;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Cari atau buat kontak Bunda
$contact = Contact::updateOrCreate(
    ['phone' => 'Mayang_Dewii'],
    [
        'name' => 'Mayang Dewii',
        'relation_type' => 'pasangan',
        'gender' => 'P'
    ]
);

$memories = [
    "Lawan bicara ini adalah Istri sah Ramlan, biasa dipanggil Bunda.",
    "Ramlan dipanggil Ayah oleh Bunda.",
    "Bunda sering bercanda intim dan santai dengan Ayah (Ramlan).",
    "Bunda sering menggoda Ramlan soal 'rajin balik' dan urusan ranjang.",
    "Bunda terkadang uring-uringan, dan Ramlan menanggapi dengan santai/bercanda.",
    "Ada anak yang sering dibahas dalam percakapan (Ayah-Bunda)."
];

foreach ($memories as $m) {
    Memory::create([
        'contact_id' => $contact->id,
        'content' => $m,
        'importance' => 5, // Sangat Penting
        'memory_type' => 'relationship',
        'source' => 'manual'
    ]);
}

echo "Memori keluarga untuk Bunda (Mayang Dewii) berhasil disimpan! ✅";
