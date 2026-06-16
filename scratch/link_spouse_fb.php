<?php

use App\Models\Contact;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fbUrl = 'https://www.facebook.com/shantidhewii';

$contact = Contact::updateOrCreate(
    ['fb_profile_url' => $fbUrl],
    [
        'name' => 'Shanti Dhewii',
        'phone' => 'Shanti_Dhewii',
        'relation_type' => 'pasangan',
        'gender' => 'P'
    ]
);

echo "Data Bunda Shanti Dhewii ({$fbUrl}) berhasil dikunci sebagai PASANGAN! ✅\n";
