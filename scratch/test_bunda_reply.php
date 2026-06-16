<?php

use App\Models\Contact;
use App\Services\AiReplyService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(AiReplyService::class);
$contact = Contact::where('phone', 'Resti_Dewi')->first();

$message = "Ayah, pusing euy mikirin wifi mana belum bayar.. pengen nangis aja rasanya. 😭";

echo "--- PESAN BUNDA ---\n" . $message . "\n\n";
echo "--- RESPON RAMLAN ---\n";
echo $service->generateReply($contact, $message);
echo "\n-------------------\n";
