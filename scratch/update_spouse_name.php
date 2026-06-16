<?php

use App\Models\Contact;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$contact = Contact::where('fb_profile_url', 'https://www.facebook.com/shantidhewii')->first();
if ($contact) {
    $contact->update([
        'name' => 'Rama Resti Dewi',
        'phone' => 'Rama_Resti_Dewi'
    ]);
    echo "Nama Bunda berhasil diupdate menjadi: Rama Resti Dewi! ✅\n";
} else {
    echo "Kontak tidak ditemukan.\n";
}
