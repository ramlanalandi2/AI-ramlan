<?php

namespace App\Services;

class HumanizerService
{
    /**
     * Memoles balasan AI agar tidak terlalu kaku.
     */
    public function apply(string $reply): string
    {
        $reply = trim($reply);

        $reply = $this->makeLessFormal($reply);
        $reply = $this->limitLength($reply);

        return $reply;
    }

    /**
     * Mengubah kata-kata formal menjadi lebih santai.
     */
    private function makeLessFormal(string $text): string
    {
        $replacements = [
            'Baik,' => 'oke,',
            'Tentu,' => 'bisa,',
            'Silakan' => 'coba',
            'Mohon' => 'coba',
            'Terima kasih' => 'makasih',
            'Saya akan' => 'nanti',
            'Saya tidak dapat' => 'belum bisa',
            'Anda' => 'Bang',
            'Saudara' => 'Bang',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $text
        );
    }

    /**
     * Memastikan pesan tidak terlalu panjang (limit 700 karakter).
     */
    private function limitLength(string $text): string
    {
        if (strlen($text) <= 700) {
            return $text;
        }

        return substr($text, 0, 700) . '...';
    }

    /**
     * Menghitung delay simulasi mengetik (dalam detik).
     */
    public function calculateTypingDelay(string $reply): int
    {
        $length = strlen($reply);

        if ($length < 80) {
            return rand(2, 4);
        }

        if ($length < 250) {
            return rand(4, 7);
        }

        return rand(7, 12);
    }

    /**
     * Mendeteksi mood user berdasarkan teks pesan.
     */
    public function detectMood(string $message): string
    {
        $text = strtolower($message);

        if (str_contains($text, 'marah') || str_contains($text, 'kecewa') || str_contains($text, 'komplain') || str_contains($text, '!!!')) {
            return 'serius';
        }

        if (str_contains($text, 'urgent') || str_contains($text, 'cepat') || str_contains($text, 'sekarang') || str_contains($text, 'buru-buru')) {
            return 'fokus';
        }

        if (str_contains($text, 'wkwk') || str_contains($text, 'haha') || str_contains($text, 'siap lan')) {
            return 'santai';
        }

        return 'normal';
    }
}
