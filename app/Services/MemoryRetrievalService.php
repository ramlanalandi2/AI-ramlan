<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Memory;

class MemoryRetrievalService
{
    /**
     * Mencari ingatan yang relevan berdasarkan keyword dalam pesan.
     */
    public function getRelevantMemories(
        Contact $contact,
        string $message,
        bool $isVerifiedSpouse = false,
        int $limit = 10
    ): array {

        // Pecah pesan jadi keyword untuk pencarian sederhana (Min 3 karakter)
        $keywords = array_values(array_filter(
            preg_split('/\s+/', strtolower($message)),
            fn($k) => strlen($k) >= 3
        ));

        if (empty($keywords)) {
            return [];
        }

        // Ambil ingatan kontak ini + ingatan global permanen (contact_id null).
        $query = Memory::where(function ($q) use ($contact) {
            $q->where('contact_id', $contact->id)
              ->orWhereNull('contact_id');
        });

        // PROTEKSI: Jika bukan verified spouse, dilarang keras ambil memori dari 'simulation'
        if (!$isVerifiedSpouse) {
            $query->where(function($q) {
                $q->where('source', '!=', 'simulation')
                  ->orWhereNull('source');
            });
        }

        // Tambahkan filter LIKE untuk setiap keyword
        $query->where(function($q) use ($keywords) {
            $first = true;
            
            foreach ($keywords as $keyword) {
                // Escape wildcard untuk keamanan query LIKE
                $safeKeyword = addcslashes($keyword, '%_\\');
                
                if ($first) {
                    $q->where('content', 'LIKE', "%{$safeKeyword}%");
                    $first = false;
                } else {
                    $q->orWhere('content', 'LIKE', "%{$safeKeyword}%");
                }
            }
        });

        return $query
            ->orderByDesc('importance')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->pluck('content')
            ->toArray();
    }
}
