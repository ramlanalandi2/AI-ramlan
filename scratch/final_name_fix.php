<?php

use App\Models\Contact;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$contact = Contact::where('fb_profile_url', 'https://www.facebook.com/shantidhewii')->first();
if ($contact) {
    $contact->update([
        'name' => 'Resti Dewi (Mayang)',
        'phone' => 'Resti_Dewi'
    ]);
    echo "Nama Bunda BERHASIL diperbaiki menjadi: Resti Dewi (Mayang)! ✅\n";
} else {
    echo "Kontak tidak ditemukan.\n";
}
