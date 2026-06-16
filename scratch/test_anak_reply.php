<?php

use App\Models\Contact;
use App\Services\AiReplyService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(AiReplyService::class);

$contact = Contact::updateOrCreate(
    ['phone' => 'TEST_ANAK'],
    ['name' => 'Adek (Anak)', 'relation_type' => 'anak']
);

$message = "Ayah, kapan pulang? Kakak kangen pengen main game bareng.. tadi Kakak dapet nilai 100 loh di sekolah! 🤩";

echo "--- TEST RELATION: ANAK ---\n";
echo "Pesan: " . $message . "\n";
echo "Respon: " . $service->generateReply($contact, $message) . "\n";
echo "---------------------------------\n";
