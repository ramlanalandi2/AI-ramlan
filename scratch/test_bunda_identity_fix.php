<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Contact;
use App\Services\AiReplyService;

$service = app(AiReplyService::class);

// Simulasi ID Facebook baru yang belum terdaftar, tapi namanya 'Mayang Dewii'
$phone = "FB_NEW_ID_" . time();
$name = "Mayang Dewii";
$message = "sok geleuh nmpo diri sndiri ge";

echo "--- SIMULASI PESAN DARI ID ASING (NAMA: MAYANG) ---\n";
echo "Pesan: '{$message}'\n\n";

$result = $service->handle($phone, $name, $message);

echo "--- HASIL ANALISA SISTEM ---\n";
echo "Relasi Terdeteksi: " . $result['contact']->relation_type . "\n";
echo "Gender: " . $result['contact']->gender . "\n\n";

echo "--- RESPON RAMLAN ---\n";
echo $result['ai_reply'] . "\n";
echo "---------------------------\n";
