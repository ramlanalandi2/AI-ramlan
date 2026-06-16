<?php

use App\Models\Contact;
use App\Services\AiReplyService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(AiReplyService::class);
$contact = Contact::where('fb_profile_url', 'https://www.facebook.com/shantidhewii')->first();

$message = "Ayah, Bunda lagi sedih banget hari ini.. rasanya pengen nangis aja, males ngapa-ngapain. Dunia serasa gak adil, pusing banget kepala Bunda. 😭 Gak usah ganggu Bunda ya, lagi gak mood.";

echo "--- PESAN BUNDA (SEDIH/BAD MOOD) ---\n" . $message . "\n\n";
echo "--- RESPON RAMLAN ---\n";

$aiReply = $service->generateReply($contact, $message);

echo $aiReply . "\n";
echo "-------------------\n";
