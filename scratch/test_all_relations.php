<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Services\AiReplyService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(AiReplyService::class);

$scenarios = [
    ['name' => 'Bunda Resti', 'relation' => 'pasangan', 'msg' => 'Ayah, kangen euy..'],
    ['name' => 'Budi (Teman)', 'relation' => 'teman', 'msg' => 'Woy Lan, ngopi yuk!'],
    ['name' => 'Mantan Istri', 'relation' => 'mantan', 'msg' => 'Ramlan, anak mau beli buku baru.'],
    ['name' => 'Unknown User', 'relation' => 'unknown', 'msg' => 'Cara instal ViralEngine gimana?'],
];

foreach ($scenarios as $s) {
    // Cari atau buat kontak dummy untuk tes
    $contact = Contact::updateOrCreate(
        ['phone' => 'TEST_' . strtoupper($s['relation'])],
        ['name' => $s['name'], 'relation_type' => $s['relation']]
    );

    echo "--- TEST RELATION: " . strtoupper($s['relation']) . " ---\n";
    echo "Pesan: " . $s['msg'] . "\n";
    try {
        echo "Respon: " . $service->generateReply($contact, $s['msg']) . "\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "---------------------------------\n\n";
}
