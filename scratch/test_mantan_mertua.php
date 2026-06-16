<?php

use App\Models\Contact;
use App\Services\AiReplyService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Pastikan ada kontak Mantan Mertua di database
$contact = Contact::updateOrCreate(
    ['phone' => 'Mantan_Mertua_Test'],
    [
        'name' => 'Ibu Mantan Mertua',
        'relation_type' => 'mantan_mertua',
        'gender' => 'P'
    ]
);

$service = app(AiReplyService::class);
$message = "A, apa kabar? Sukses terus ya bisnisnya. Kapan atuh nengok Arfa ke sini? Dia nanyain Aa terus.";

echo "--- PESAN MANTAN MERTUA ---\n" . $message . "\n\n";
echo "--- RESPON RAMLAN ---\n";

$aiReply = $service->generateReply($contact, $message);

echo $aiReply . "\n";
echo "-------------------\n";
