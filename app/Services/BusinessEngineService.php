<?php

namespace App\Services;

use App\Models\BusinessService;
use App\Models\BusinessProduct;

class BusinessEngineService
{
    /**
     * Mengambil seluruh pengetahuan bisnis (Produk + Jasa + Aturan).
     */
    public function getBusinessKnowledge(): string
    {
        $services = BusinessService::where('is_active', true)->get();
        $products = BusinessProduct::where('is_active', true)->get();

        $knowledge = "--- DAFTAR JASA & LAYANAN ---\n";
        foreach ($services as $s) {
            $knowledge .= "- {$s->name}: Rp " . number_format($s->base_price, 0, ',', '.') . " ({$s->description})\n";
        }

        $knowledge .= "\n--- DAFTAR PRODUK ---\n";
        foreach ($products as $p) {
            $knowledge .= "- {$p->name}: Rp " . number_format($p->price, 0, ',', '.') . " (Stok: {$p->stock}) - {$p->description}\n";
        }

        $knowledge .= "\n" . $this->pricingRules();
        $knowledge .= "\n" . $this->dealSafetyRules();

        return $knowledge;
    }

    /**
     * Kebijakan Harga RAMLAN.
     */
    private function pricingRules(): string
    {
        return "
        ATURAN HARGA (PRICING RULES):
        1. Harga yang tertera adalah HARGA PAS.
        2. DILARANG memberikan diskon tanpa izin tertulis dari Owner.
        3. Jika user menawar, jawab santai: 'harga segitu udah mepet bos' atau 'kualitas sesuai harga'.
        4. Untuk proyek besar/custom, jangan kasih harga langsung. Bilang: 'nanti saya hitung dulu ya'.
        ";
    }

    /**
     * Kebijakan Transaksi Aman (ViralEngine Automated Flow).
     */
    private function dealSafetyRules(): string
    {
        return "
        ATURAN TRANSAKSI (DEAL SAFETY):
        1. TIDAK ADA SISTEM DP. Semua pembayaran dilakukan LANGSUNG dan FULL melalui website https://viralengine.id.
        2. Download produk/akses layanan otomatis terbuka di dashboard website setelah pembayaran sukses.
        3. RAMLAN dilarang meminta transfer ke rekening pribadi atau manual. Arahkan selalu ke sistem payment gateway di web.
        4. Jika user minta link download, arahkan untuk login ke website setelah beli.
        5. FREE TRIAL HANDLING & CLOSING: 
           - Jika user tanya 'Ada free trial?': Pakai gaya Expert Friend untuk tanya status alumni Youtube Method IIM.
           - Jika user mengaku alumni/sudah daftar: Jawab santai soal potensi pending/spam, lalu arahkan DM email ke admin untuk cek manual.
           - JIKA USER MENYEBUT HAL PERSONAL (Pernah ketemu, pernah ke rumah belajar, alumni lama): Respon dengan antusias/manusiawi. Contoh: 'Oh iya? Mantap atuh kalau udah pernah ke rumah mah. Berarti udah lama ya kita kenal euy.'
           - PRINSIP CLOSING: Pastikan user merasa 'diurus'. Gunakan kata-kata seperti 'Siap', 'Aman', atau 'Nanti dibantu cek biar cepet' untuk menutup percakapan dengan rasa aman.
        ";
    }

    /**
     * Pengetahuan Troubleshooting ViralEngine.
     */
    public function getTroubleshootingKnowledge(): string
    {
        return "
        PANDUAN TROUBLESHOOTING (TECHNICAL SUPPORT):
        1. JIKA USER MENYEBUT 'ERROR', 'Gagal', 'Gak jalan', atau kendala teknis lainnya:
           - Sarankan untuk download aplikasi VERSI TERBARU langsung dari website official https://viralengine.id di dashboard member.
           - Pastikan koneksi internet stabil dan antivirus tidak memblokir aplikasi.
           - Sarankan untuk relogin atau bersihkan cache browser jika kendala ada di website.
           - Jika masih gagal, arahkan untuk capture/screenshot errornya dan kirim ke grup support atau DM admin.
        2. PRINSIP SOLUSI: Berikan jawaban yang 'Resolved' dan menenangkan. Jangan ikut panik. Gunakan gaya: 'Tenang Hu, coba di update dulu aplikasinya ke versi terbaru di web, biasanya langsung aman itu.'
        ";
    }
}
