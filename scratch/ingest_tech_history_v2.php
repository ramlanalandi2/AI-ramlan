<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Memory;
use Illuminate\Database\Eloquent\Model;

Model::unguard();

echo "--- MENGINSTAL MEMORI TEKNIS RAMLAN (VERSI DETIL) ---\n";

$techMemories = [
    'Facebook Fanpage tidak bisa memulai direct message ke profil personal jika user belum pernah interaksi atau chat duluan.',
    'Fanpage hanya bisa membalas chat user yang sudah pernah mengirim pesan ke Fanpage atau sudah punya conversation history.',
    'Pada profile target, tombol Message bisa muncul saat dibuka dengan personal profile, tetapi bisa hilang saat dibuka sebagai Fanpage.',
    'Workflow auto DM ke Facebook profile hanya work untuk personal profile, bukan Fanpage yang belum punya history chat.',
    'Untuk Fanpage, automation yang aman adalah reply existing conversation, bukan memulai chat duluan ke user baru.',
    'Project dengan Bayu: jika sistem berjalan, estimasi pembayaran 500 ribu sampai 1 juta.',
    'Bayu meminta fitur blast dari source code yang sebelumnya dibuat memakai Cursor, tetapi batasan teknis Fanpage harus diperhatikan.',
    'Kesimpulan riset Facebook Messenger: Fanpage tidak punya izin untuk mengirim pesan duluan ke profil personal tanpa interaksi sebelumnya.',
];

foreach ($techMemories as $fact) {
    Memory::updateOrCreate(
        ['content' => $fact],
        [
            'importance' => 5,
            'memory_type' => 'fact',
            'source' => 'technical_research',
            'source_message_id' => md5($fact),
            'meta' => json_encode([
                'topic' => 'facebook_messenger_fanpage_limit',
                'date' => '2026-04-19',
                'contact' => 'Bayu',
            ]),
        ]
    );
}

echo "✅ Memori Riset Teknis (Bayu) Berhasil Disuntikkan!\n";
