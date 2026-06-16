<?php

use App\Models\Contact;
use App\Models\Memory;
use App\Services\AiReplyService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(AiReplyService::class);
$contact = Contact::where('fb_profile_url', 'https://www.facebook.com/shantidhewii')->first();

if (!$contact) {
    echo "Kontak Bunda tidak ditemukan!\n";
    exit;
}

$testPrompts = [
    "Ayah, Bunda lagi pengen dimanja banget hari ini.. capek ngurus rumah sendirian. 🥺",
    "Yah, liat deh koleksi baru Bunda.. bagus gak buat dipake nanti malam? 😏",
    "Ayah, Bunda seneng banget Kakak dapet ranking 1! Ayah bangga gak? 🥰",
    "Pusing euy tagihan banyak, wifi juga lemot.. kumaha atuh Yah? 😭",
    "Ayah, sayangnya Bunda segimana sih? Coba buktiin.. 😛",
    "Yah, kangen dipeluk.. pengen cepet-cepet Ayah pulang. 😗",
    "Malam ini khusus berduaan aja ya Yah, Kakak udah tidur di kamar sebelah. 😏🔥",
    "Ayah, Bunda lagi gak enak badan euy.. meriang jiga na mah. 🤒",
    "Yah, nanti kalo kita udah sukses, Bunda pengen kita jalan-jalan ke luar negeri bareng Kakak ya. 🌍",
    "Ayah, makasih ya udah kerja keras buat Bunda sama Kakak. I love you! ❤️"
];

echo "Memulai Generasi Memori Emas dari Real AI...\n\n";

foreach ($testPrompts as $index => $msg) {
    echo "Skenario " . ($index + 1) . ": " . $msg . "\n";
    
    // Generate real response from AI
    $aiReply = $service->generateReply($contact, $msg);
    
    if ($aiReply && !str_contains($aiReply, 'urusan mendadak')) {
        echo "Respon AI: " . $aiReply . "\n";
        
        // Simpan respon real ini ke Memori
        Memory::create([
            'contact_id' => $contact->id,
            'memory_type' => 'conversation_pattern',
            'source' => 'real_ai_golden',
            'content' => "Bunda: '{$msg}' -> Ayah: '{$aiReply}'",
            'importance' => 5
        ]);
        
        echo "✅ Berhasil disimpan ke Memori Emas.\n\n";
    } else {
        echo "❌ Gagal mendapatkan respon AI atau kena fallback. Melewati skenario ini.\n\n";
    }
    
    // Sleep lebih lama agar tidak kena rate limit
    sleep(5);
}

echo "Generasi Memori Emas SELESAI! 🧠👑🔥✅\n";
