<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Memory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MemoryExtractorService
{
    protected $api_key;

    public function __construct()
    {
        $this->api_key = config('services.openrouter.key');
    }

    /**
     * Menganalisa pesan dan menyimpan fakta penting ke database via OpenRouter.
     */
    public function extract(Contact $contact, string $messageText)
    {
        if (empty($this->api_key)) {
            return;
        }

        $prompt = "Kamu adalah sistem pengekstrak informasi penting. Tugas kamu adalah mengambil fakta, preferensi, nama, atau informasi penting lainnya dari pesan chat singkat.
        
        Pesan: \"{$messageText}\"
        
        Jika ada informasi penting tentang orang ini, tuliskan dalam format JSON:
        {
            \"has_info\": true,
            \"fact\": \"isi fakta yang ditemukan\",
            \"type\": \"fact/preference/todo/important\",
            \"importance\": 1-5
        }
        Jika tidak ada informasi penting (hanya basa-basi seperti 'wkwk', 'oke', 'p'), balas: {\"has_info\": false}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->api_key,
                'HTTP-Referer' => config('app.url'),
                'Content-Type' => 'application/json',
            ])->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => config('services.openrouter.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah asisten database yang cerdas.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0,
            ]);

            if ($response->successful()) {
                $data = $response->json('choices.0.message.content');
                $info = json_decode($data, true);

                if (isset($info['has_info']) && $info['has_info']) {
                    Memory::create([
                        'contact_id' => $contact->id,
                        'memory_type' => $info['type'] ?? 'fact',
                        'content' => $info['fact'],
                        'importance' => $info['importance'] ?? 3,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Memory Extraction Error: " . $e->getMessage());
        }
    }
}
