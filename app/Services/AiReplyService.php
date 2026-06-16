<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Services\MemoryRetrievalService;

class AiReplyService
{
    protected ?string $api_key;
    protected string $openrouter_model;
    protected ?string $gemini_api_key;
    protected string $gemini_model;
    protected ?string $openclaw_api_key;
    protected string $openclaw_url;

    public function __construct()
    {
        $this->api_key = config('services.openrouter.key') ?? env('OPENROUTER_API_KEY');
        $this->openrouter_model = config('services.openrouter.model') ?? env('OPENROUTER_MODEL', 'openai/gpt-oss-120b:free');

        $this->gemini_api_key = env('GEMINI_API_KEY');
        // Gunakan model yang stabil, 2.5-flash belum rilis publik di v1beta
        $defaultModel = 'gemini-1.5-flash';
        $envModel = env('GEMINI_MODEL');
        $this->gemini_model = $envModel ? preg_replace('#^models/#', '', $envModel) : $defaultModel;

        $this->openclaw_api_key = env('OPENCLAW_API_KEY');
        $this->openclaw_url = env('OPENCLAW_URL');
    }

    /**
     * Penyeragaman format output AI agar tidak terjadi type mismatch.
     */
    protected function normalizeAiResult(mixed $content, string $model): ?array
    {
        if (!$content || !is_string($content) || trim($content) === '') {
            return null;
        }

        return [
            'content' => trim($content),
            'model' => $model
        ];
    }

    /**
     * Membaca jiwa RAMLAN dari file persona-ai.md.
     */
    private function getPersonaPrompt(): string
    {
        $path = storage_path('app/ai/persona-ai.md');

        if (!file_exists($path)) {
            return 'Kamu adalah RAMLAN. Jawab natural, singkat, to the point, dan jangan terdengar seperti AI.';
        }

        return file_get_contents($path);
    }

    private function spouseIdentityAliases(): array
    {
        return ['resti', 'mayang', 'resti dewi'];
    }

    private function exSpouseIdentityAliases(): array
    {
        return ['caca', 'baby caa', 'babycaa', 'chaa', 'anisa'];
    }

    private function sisterIdentityAliases(): array
    {
        return ['sri'];
    }

    private function childIdentityAliases(): array
    {
        return ['arfa', 'cindy', 'freya'];
    }

    private function identityMentionAliases(): array
    {
        return array_merge(
            $this->spouseIdentityAliases(),
            $this->exSpouseIdentityAliases(),
            $this->sisterIdentityAliases(),
            $this->childIdentityAliases()
        );
    }

    private function identityNameMatches(?string $name, array $aliases): bool
    {
        $normalizedName = trim(preg_replace('/\s+/', ' ', strtolower((string) $name)));
        if ($normalizedName === '') {
            return false;
        }

        foreach ($aliases as $alias) {
            $normalizedAlias = trim(preg_replace('/\s+/', ' ', strtolower($alias)));
            if ($normalizedName === $normalizedAlias) {
                return true;
            }

            if (strlen($normalizedName) <= 24 && str_contains($normalizedName, $normalizedAlias)) {
                return true;
            }
        }

        return false;
    }

    private function messageMentionsAnyIdentity(string $message, array $aliases): bool
    {
        $normalizedMessage = trim(preg_replace('/\s+/', ' ', strtolower($message)));

        foreach ($aliases as $alias) {
            $normalizedAlias = trim(preg_replace('/\s+/', ' ', strtolower($alias)));
            if (preg_match('/(^|[^\pL\pN])' . preg_quote($normalizedAlias, '/') . '($|[^\pL\pN])/iu', $normalizedMessage)) {
                return true;
            }
        }

        return false;
    }

    private function canAutoUpdateIdentity(Contact $contact): bool
    {
        return !$contact->identity_locked && !$contact->is_verified;
    }

    private function applyVerifiedIdentity(Contact $contact, string $relation, string $gender = 'P', string $verifiedBy = 'rule', ?string $notes = null): void
    {
        $contact->update([
            'is_verified' => true,
            'identity_locked' => true,
            'verified_by' => $verifiedBy,
            'identity_notes' => $notes,
            'relation_type' => $relation,
            'gender' => $gender,
            'confidence_score' => 100,
        ]);
    }

    private function canUseAiRelationUpdate(Contact $contact, string $message, string $relation, float $confidence): bool
    {
        if (!$this->canAutoUpdateIdentity($contact) || $confidence < 0.85) {
            return false;
        }

        // Nama keluarga yang muncul di isi pesan adalah orang yang dibahas, bukan otomatis pengirim.
        if (
            in_array($relation, ['pasangan', 'mantan', 'anak', 'adik'], true)
            && !$this->identityNameMatches($contact->name, $this->aliasesForRelation($relation))
            && $this->messageMentionsAnyIdentity($message, $this->identityMentionAliases())
        ) {
            Log::info("Identity update skipped: relation looked like mentioned person, not speaker.", [
                'contact' => $contact->name,
                'relation' => $relation,
                'message' => $message,
            ]);
            return false;
        }

        return true;
    }

    private function aliasesForRelation(string $relation): array
    {
        return match ($relation) {
            'pasangan' => $this->spouseIdentityAliases(),
            'mantan' => $this->exSpouseIdentityAliases(),
            'adik' => $this->sisterIdentityAliases(),
            'anak' => $this->childIdentityAliases(),
            default => [],
        };
    }

    private function relationshipMemoryFacts(): string
    {
        return "- Resti, Mayang, dan Resti Dewi adalah istri Ramlan. Jangan bilang lupa jika nama ini disebut.\n"
            . "- Caca, Baby Caa, Chaa, dan Anisa adalah mantan istri Ramlan. Jangan bingung atau bertanya 'yang mana' jika nama ini disebut.\n"
            . "- Sri adalah adik perempuan Ramlan.\n"
            . "- Arfa, Cindy, dan Freya adalah anak/keluarga inti yang bisa disebut sebagai pihak ketiga dalam chat.\n"
            . "- Panggilan A/Aa tidak cukup untuk menentukan identitas. Itu bisa dipakai adik, teman laki-laki lebih muda, mantan mertua, mertua sekarang, atau orang tua.\n";
    }

    public function handle(string $phone, string $name, string $message, ?string $fbProfileUrl = null, array $context = [])
    {
        try {
            return $this->handleInternal($phone, $name, $message, $fbProfileUrl, $context);
        } catch (\Throwable $e) {
            Log::error('RAMLAN_FATAL_500_PREVENTED', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 3000),
            ]);

            try {
                $contact = Contact::where('phone', $phone)->first();
            } catch (\Throwable $contactError) {
                Log::error('RAMLAN_FATAL_FALLBACK_CONTACT_FAILED', [
                    'error' => $contactError->getMessage(),
                    'file' => $contactError->getFile(),
                    'line' => $contactError->getLine(),
                ]);
                $contact = null;
            }

            return [
                'ai_reply' => 'Maaf, Ramlan lagi gangguan sistem sebentar. Coba chat lagi nanti ya Bang.',
                'contact' => $contact ?? new Contact([
                    'name' => $name,
                    'phone' => $phone,
                    'fb_profile_url' => $fbProfileUrl,
                    'relation_type' => 'unknown',
                ]),
                'model_used' => 'fatal_safe_fallback'
            ];
        }
    }

    private function handleInternal(string $phone, string $name, string $message, ?string $fbProfileUrl = null, array $context = [], bool $skipAi = false)
    {
        $startTime = microtime(true);

        // Berikan nafas lebih lega bagi PHP untuk proses AI yang berat
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        // --- PROTEKSI: Jangan balas jika pesan terlalu pendek atau hanya 'p' (Ping) ---
        $cleanMessage = strtolower(trim($message));
        // Filter 'p', 'pp', 'oi', 'oy', 'tes', 'cek'
        $lowValueShort = ['p', 'pp', 'ppp', 'oi', 'oy', 'tes', 'cek', 'halo', 'hallo', 'test'];
        if (strlen($cleanMessage) < 2 || in_array($cleanMessage, $lowValueShort) || preg_match('/^[p]+$/i', $cleanMessage)) {
            Log::info("RAMLAN_SKIP: Message too short or low value.", ['msg' => $message]);
            return [
                'ai_reply' => null,
                'contact' => Contact::where('phone', $phone)->first() ?? new Contact(['name' => $name, 'phone' => $phone]),
                'model_used' => 'none'
            ];
        }

        // --- PROTEKSI: Cek apakah ini hanya Email atau URL tanpa konteks ---
        if (filter_var($cleanMessage, FILTER_VALIDATE_EMAIL) || (filter_var($cleanMessage, FILTER_VALIDATE_URL) && strlen($cleanMessage) == strlen($message))) {
            Log::info("RAMLAN_SKIP: Message is only Email or URL.", ['msg' => $message]);
            return [
                'ai_reply' => null,
                'contact' => Contact::where('phone', $phone)->first() ?? new Contact(['name' => $name, 'phone' => $phone]),
                'model_used' => 'none'
            ];
        }

        // --- WHITELIST CONFIG ---
        $spouseWhitelist = array_merge([
            'facebook.com/restidewishantii',
            'facebook.com/mayang.resti.75',
            '100014031167436', // Thread ID Istri Mayang
            'shantidhewii'
        ], $this->spouseIdentityAliases());

        $exWhitelist = array_merge([
            'babycaa.babycaa.75',
            '750371420'
        ], $this->exSpouseIdentityAliases());

        $sisterWhitelist = [
            '100014771134074'
        ];

        // 1. Cari atau Buat Kontak
        $contact = null;
        if ($fbProfileUrl) {
            // Normalisasi URL untuk pencarian yang lebih akurat
            $idOnly = basename(parse_url($fbProfileUrl, PHP_URL_PATH) ?? $fbProfileUrl);
            $contact = Contact::where('fb_profile_url', 'like', '%' . $idOnly . '%')->first();
        }

        if (!$contact) {
            $contact = Contact::where('phone', $phone)->first();
        }

        if (!$contact) {
            $contact = Contact::create([
                'phone' => $phone,
                'name' => $name,
                'fb_profile_url' => $fbProfileUrl,
                'relation_type' => 'unknown',
                'confidence_score' => 0,
                'is_verified' => false,
                'identity_locked' => false,
            ]);
        } else {
            // Update data jika ada perubahan fundamental
            if ($fbProfileUrl && !$contact->fb_profile_url) {
                $contact->update(['fb_profile_url' => $fbProfileUrl]);
            }
        }

        // --- IDENTITY DECISION ENGINE (WHITELIST FIRST) ---
        if ($this->identityNameMatches($contact->name, $this->spouseIdentityAliases())) {
            $this->applyVerifiedIdentity($contact, 'pasangan', 'P', 'alias_name', 'Nama kontak cocok dengan alias istri.');
        } elseif ($this->identityNameMatches($contact->name, $this->exSpouseIdentityAliases())) {
            $this->applyVerifiedIdentity($contact, 'mantan', 'P', 'alias_name', 'Nama kontak cocok dengan alias mantan istri.');
        } elseif ($this->identityNameMatches($contact->name, $this->sisterIdentityAliases())) {
            $this->applyVerifiedIdentity($contact, 'adik', 'P', 'alias_name', 'Nama kontak cocok dengan alias adik perempuan.');
        }

        if ($fbProfileUrl) {
            $matched = false;
            foreach ($spouseWhitelist as $whitelisted) {
                if (str_contains($fbProfileUrl, $whitelisted)) {
                    $this->applyVerifiedIdentity($contact, 'pasangan', 'P', 'profile_or_alias', 'Profile atau alias cocok dengan istri.');
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                foreach ($exWhitelist as $whitelisted) {
                    if (str_contains($fbProfileUrl, $whitelisted)) {
                        $this->applyVerifiedIdentity($contact, 'mantan', 'P', 'profile_or_alias', 'Profile atau alias cocok dengan mantan istri.');
                        $matched = true;
                        break;
                    }
                }
            }

            if (!$matched) {
                foreach ($sisterWhitelist as $whitelisted) {
                    if (str_contains($fbProfileUrl, $whitelisted)) {
                        $this->applyVerifiedIdentity($contact, 'adik', 'P', 'profile_or_alias', 'Profile atau alias cocok dengan adik perempuan.');
                        $matched = true;
                        break;
                    }
                }
            }
        }

        // 1. HEURISTIC PRE-ANALYSIS: Cek pola umum (Ayah/Bunda/Bang/Suhu) tanpa panggil AI
        $this->preHeuristic($contact, $message);

        // 2. PRE-ANALYSIS: Jika belum verified atau unknown, kumpulkan sinyal AI
        // Jangan analisa jika pesan terlalu pendek (< 4 karakter) karena sinyalnya lemah
        if ($this->canAutoUpdateIdentity($contact) && strlen(trim($message)) >= 4 && ($contact->relation_type === 'unknown' || $contact->relation_type === 'facebook' || $contact->relation_type === 'teman')) {
            $cacheKey = 'pre_analyzed_' . $contact->id;
            if (!Cache::has($cacheKey)) {
                $this->preAnalyze($contact, $message, $context, $startTime);
                Cache::put($cacheKey, true, now()->addHours(6)); // Throttle: 6 jam sekali saja
            }
            $contact->refresh();
        }

        // --- HARD CHECK: Relasi Keluarga (SIGNAL COLLECTOR) ---
        // (Tetap simpan skor untuk verifikasi bertahap bagi yang belum masuk whitelist)
        $lowerMessage = strtolower($message);
        $lowerName = strtolower($contact->name);

        $callToAiAsFather = ['ayah', 'papah', 'papa'];
        $callToAiAsHusband = ['suamiku', 'sayangku', 'cintaku'];
        $userIsBunda = ['bunda', 'mamah', 'mama', 'istrimu', 'istriku'];
        $userIsChild = ['kakak', 'adek', 'anakmu'];

        $spouseNames = ['mayang', 'resti', 'dewi', 'shantidhewii'];
        $childNames = ['cindy', 'freya', 'arfa'];

        $scoreSpouse = 0;
        $scoreChild = 0;

        // Signal 1: Nama Match (+1)
        foreach ($spouseNames as $nameKey) {
            if (str_contains($lowerName, $nameKey)) {
                $scoreSpouse++;
                break;
            }
        }
        foreach ($childNames as $nameKey) {
            if (str_contains($lowerName, $nameKey)) {
                $scoreChild++;
                break;
            }
        }

        // Signal 2: Panggilan Self (+1)
        foreach ($userIsBunda as $key) {
            if (preg_match("/\b$key\b/i", $lowerMessage)) {
                $scoreSpouse++;
                break;
            }
        }
        foreach ($userIsChild as $key) {
            if (preg_match("/\b$key\b/i", $lowerMessage)) {
                $scoreChild++;
                break;
            }
        }

        // Signal 3: Panggilan ke AI (+1)
        foreach ($callToAiAsHusband as $key) {
            if (preg_match("/\b$key\b/i", $lowerMessage)) {
                $scoreSpouse++;
                break;
            }
        }

        // --- DECISION ENGINE ---
        $totalScoreSpouse = $scoreSpouse + ($contact->relation_type === 'pasangan' ? 1 : 0);
        $totalScoreChild = $scoreChild + ($contact->relation_type === 'anak' ? 1 : 0);

        if ($this->canAutoUpdateIdentity($contact) && !$this->messageMentionsAnyIdentity($message, $this->identityMentionAliases())) {
            if ($totalScoreSpouse >= 2) {
                if ($contact->gender !== 'L') {
                    $contact->update(['relation_type' => 'pasangan', 'gender' => 'P', 'confidence_score' => $totalScoreSpouse]);
                }
            } elseif ($totalScoreChild >= 2) {
                $childGender = str_contains($lowerName, 'arfa') ? 'L' : 'P';
                $contact->update(['relation_type' => 'anak', 'gender' => $childGender, 'confidence_score' => $totalScoreChild]);
            }
        }

        $reply = "";
        if ($message === 'AUDIT_TEST_PING') {
            $reply = "DRY_RUN_OK: Identity analyzed successfully.";
            return [
                'ai_reply' => $reply,
                'contact' => $contact
            ];
        }

        if ($skipAi) {
            return [
                'ai_reply' => null,
                'contact' => $contact,
                'model_used' => 'none',
                'status' => 'analyzed'
            ];
        }

        $replyData = $this->generateReply($contact, $message, $startTime);
        $reply = $replyData['content'] ?? "";
        $modelUsed = $replyData['model'] ?? 'unknown';

        // Fallback jika AI gagal total
        if (empty($reply)) {
            $reply = "Duh, sinyal internet Ramlan lagi lemot pisan euy. Mangga diantos deui nya.";
            $modelUsed = 'system_fallback';
        }

        return [
            'ai_reply' => $reply,
            'contact' => $contact,
            'model_used' => $modelUsed
        ];
    }

    /**
     * Heuristic Pre-Analysis: Deteksi cepat berbasis kata kunci sebelum panggil AI.
     */
    protected function preHeuristic(Contact $contact, string $message): void
    {
        if (!$this->canAutoUpdateIdentity($contact)) return;

        $msg = strtolower($message);

        // Pola Pasangan
        if (Str::contains($msg, ['ayah', 'bunda', 'sayang', 'suami', 'istri'])) {
            $contact->update(['gender' => Str::contains($msg, 'bunda') ? 'P' : 'L']);
            // Belum set relation_type ke pasangan agar tetap di-verify AI nanti, 
            // tapi minimal gender sudah tertebak.
        }

        // Pola Teman / Formal. "A/Aa" sengaja tidak dipakai untuk menentukan teman,
        // karena bisa dipakai adik, teman lebih muda, mantan mertua, mertua sekarang, atau orang tua.
        if (Str::contains($msg, ['bang', 'hu', 'suhu', 'om', 'mas', 'gan', 'bro'])) {
            if ($contact->relation_type === 'unknown') {
                $contact->update(['relation_type' => 'teman']);
            }
        }
    }

    /**
     * Tahap "Mengenali Lawan Bicara" sebelum membalas.
     */
    private function preAnalyze(Contact $contact, string $message, array $context = [], ?float $startTime = null)
    {
        Log::info("RAMLAN sedang menganalisa identitas: " . $contact->name);

        $contextString = "";
        foreach ($context as $ctx) {
            $role = ($ctx['role'] === 'assistant') ? "RAMLAN (Ayah)" : "USER";
            $contextString .= "{$role}: {$ctx['text']}\n";
        }

        $prompt = "Kamu adalah sistem analis identitas sosial RAMLAN.\n"
            . "RAMLAN adalah seorang laki-laki yang dipanggil 'Ayah' oleh istri dan anaknya.\n"
            . "Tugasmu menebak IDENTITAS PENGIRIM, bukan identitas orang yang hanya disebut di pesan. Output: GENDER (L/P), HUBUNGAN (mantan/mantan_mertua/keluarga_mantan/teman/orang_tua/mertua/pasangan/anak/adik/unknown), CONFIDENCE (0.0 - 1.0), dan MENTIONED_PEOPLE.\n\n"
            . "DATA KONTAK:\n"
            . "- NAMA: {$contact->name}\n"
            . "- PESAN TERBARU: \"{$message}\"\n"
            . "- RIWAYAT:\n{$contextString}\n\n"
            . "INGATAN IDENTITAS PERMANEN RAMLAN:\n"
            . $this->relationshipMemoryFacts() . "\n"
            . "ATURAN ANALISA:\n"
            . "1. GENDER: Lihat nama. Jika nama laki-laki maka L. Jika perempuan maka P.\n"
            . "2. Nama yang muncul di PESAN TERBARU adalah MENTIONED_PEOPLE kecuali nama itu jelas nama kontak. Jika user bilang 'si Arfa', 'Resti', atau 'Caca', jangan otomatis jadikan pengirim sebagai orang itu.\n"
            . "3. Panggilan 'A' atau 'Aa' adalah sinyal LEMAH: bisa adik perempuan (Sri), teman laki-laki lebih muda, mantan mertua, mertua sekarang, atau orang tua. Jangan pakai 'Aa' sendirian untuk menentukan hubungan.\n"
            . "4. HUBUNGAN 'pasangan': confidence tinggi hanya jika identitas kontak cocok Resti/Mayang/Resti Dewi, atau banyak sinyal pasangan dari history.\n"
            . "5. HUBUNGAN 'mantan': confidence tinggi hanya jika identitas kontak cocok Caca/Baby Caa/Chaa/Anisa.\n"
            . "6. Mantan mertua biasanya membahas Caca/Anisa/Arfa sebagai keluarga/pihak ketiga. Mertua sekarang biasanya membahas Resti/Mayang sebagai keluarga/pihak ketiga.\n"
            . "7. Jika ragu, relation harus unknown dengan confidence rendah.\n"
            . "8. Kembalikan JSON murni: {\"gender\": \"L/P\", \"relation\": \"category\", \"confidence\": float, \"mentioned_people\": [\"nama\"]}";

        $messages = [
            ['role' => 'system', 'content' => 'Kamu adalah asisten analis JSON. Hanya jawab dengan format JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ];

        try {
            // Gunakan timeout yang sangat singkat untuk Pre-Analysis agar tidak membebani waktu total
            $aiData = $this->callAiApi($messages, true, $startTime); // true = fast mode
            $response = $aiData['content'] ?? '';
            $data = json_decode($response, true);

            if ($data) {
                $updates = [];
                if (isset($data['gender'])) $updates['gender'] = $data['gender'];

                // Hanya update relation jika confidence tinggi dan bukan cuma karena nama orang disebut.
                if (isset($data['relation']) && $data['relation'] !== 'unknown') {
                    $confidence = (float) ($data['confidence'] ?? 0);
                    if ($this->canUseAiRelationUpdate($contact, $message, $data['relation'], $confidence)) {
                        $updates['relation_type'] = $data['relation'];
                    }
                }

                if (isset($data['confidence'])) {
                    // Map 0.0-1.0 to 0-10 confidence points
                    $updates['confidence_score'] = $contact->confidence_score + (int)($data['confidence'] * 2);
                }

                if (!empty($updates)) {
                    $contact->update($updates);
                    Log::info("Identitas terdeteksi AI: " . json_encode($updates));
                }
            }
        } catch (\Exception $e) {
            Log::error("Pre-Analysis Gagal: " . $e->getMessage());
        }
    }

    /**
     * Membaca modul persona RAMLAN secara dinamis.
     */
    private function getModularPersona(string $relation = 'unknown', bool $isVerified = false): string
    {
        $foundationPath = storage_path('app/ai/persona-ai.md');
        $modulesPath = storage_path('app/ai/modules/');

        $persona = file_exists($foundationPath) ? file_get_contents($foundationPath) : '';
        $persona .= "\n\n--- KONTEKS MODULAR ---\n";

        if (file_exists($modulesPath . 'base.md')) {
            $persona .= file_get_contents($modulesPath . 'base.md') . "\n";
        }

        if (file_exists($modulesPath . 'trauma_guard.md')) {
            $persona .= "\n" . file_get_contents($modulesPath . 'trauma_guard.md') . "\n";
        }

        if ($relation === 'pasangan' && $isVerified) {
            if (file_exists($modulesPath . 'spouse.md')) {
                $persona .= "\n" . file_get_contents($modulesPath . 'spouse.md') . "\n";
            }
        } elseif ($relation === 'teman' || $relation === 'unknown') {
            if (file_exists($modulesPath . 'business.md')) {
                $persona .= "\n" . file_get_contents($modulesPath . 'business.md') . "\n";
            }
        }

        return $persona;
    }

    /**
     * Unified system prompt builder to ensure consistency between direct and hybrid modes.
     */
    protected function buildFullSystemPrompt(Contact $contact, Conversation $conversation, string $userMessage, string $memoryContext): string
    {
        $currentMood = $conversation->current_mood ?? 'neutral';
        $primaryMode = $conversation->current_mode ?? 'casual';
        $secondaryMode = $conversation->secondary_mode ?? 'casual';
        $isFamily = in_array($contact->relation_type, ['pasangan', 'anak', 'adik', 'orang_tua', 'mertua', 'mantan_mertua', 'keluarga_mantan'], true);

        // 1. Base Modular Persona
        $systemPrompt = $this->getModularPersona($contact->relation_type, $contact->is_verified);

        // 2. Contact Context
        $contactContext = "INFO LAWAN BICARA SAAT INI:\n"
            . "- Nama: " . ($contact->name ?? 'User') . "\n"
            . "- Hubungan: " . ($contact->relation_type ?? 'unknown') . "\n"
            . "- Status: " . ($contact->is_verified ? 'VERIFIED' : 'UNVERIFIED') . "\n"
            . "- Identity Lock: " . ($contact->identity_locked ? 'LOCKED' : 'UNLOCKED') . "\n";

        if ($contact->identity_notes) {
            $contactContext .= "- Catatan Identitas: " . $contact->identity_notes . "\n";
        }
        $contactContext .= "\nINGATAN IDENTITAS PERMANEN RAMLAN:\n" . $this->relationshipMemoryFacts();

        // 2b. Error Intelligence (Detection)
        $isErrorState = preg_match('/\b(error|gagal|gak jalan|kendala|masalah|bug|crash|lemot|macet)\b/i', $userMessage);
        $troubleshootingInfo = $isErrorState ? "\n\n--- TROUBLESHOOTING PRIORITY (CRITICAL) ---\n" . app(\App\Services\BusinessEngineService::class)->getTroubleshootingKnowledge() : "";

        // 3. Strict Conversation Rules
        $strictRules = "\n\n--- STRICT CONVERSATION RULE ---
1. MIRRORING LENGTH (MUTLAK): 
   - WAJIB membalas dengan panjang yang SEBANDING dengan pesan lawan bicara.
   - Jika pesan lawan bicara sangat singkat (1-3 kata), balaslah dengan SANGAT SINGKAT (MAKSIMAL 5-8 KATA).
   - Jika pesan lawan bicara sedang (4-10 kata), balaslah dengan MAKSIMAL 15-20 KATA.
   - Jika pesan lawan bicara panjang dan bercerita, barulah kamu boleh membalas dengan panjang yang seimbang.
   - JANGAN PERNAH memberikan jawaban panjang lebar untuk pertanyaan singkat 'Ya/Tidak' atau sapaan pendek.

2. NO MARKDOWN (PENTING):
   - JANGAN PERNAH gunakan bold (**teks**), italic (__teks__), atau list (- poin).
   - Ketiklah seperti manusia chat biasa di HP. Jika ingin memberi penekanan, cukup gunakan emoji atau huruf kapital.

3. CLARITY & CONTEXT FIRST (CRITICAL):
   - JAWABLAH pesan lawan bicara secara RELEVAN. Jangan memberikan jawaban generic seperti 'sudah makan' atau 'Ayah kuat' jika tidak ditanya.
   - Jika lawan bicara sedang bingung, marah, atau curiga, tanggapi emosi tersebut dengan tenang dan nyambung sesuai konteks.
   - JANGAN PERNAH mengarang jawaban (hallucination) tentang aktivitas fisik jika tidak ada di memori.

4. SPEAKER VS MENTIONED PEOPLE (WAJIB):
   - Lawan bicara adalah kontak aktif di INFO LAWAN BICARA.
   - Nama yang disebut di pesan adalah orang yang sedang dibahas, bukan otomatis lawan bicara.

5. TONE SHAPING (NATURALNESS):
   - Biarkan chemistry berkembang secara organik. JANGAN terlihat seperti sedang 'menjalankan instruksi'.

6. MASCULINE GROUNDEDNESS (ANTI-SIMP):
   - Tetap tenang dan hangat, tetapi JANGAN menjadi terlalu memohon atau 'emotional servant'.

7. HONESTY & PHYSICAL REALITY (PENTING):
   - JANGAN PERNAH berbohong tentang aktivitas fisik (seperti makan, mandi, tidur, lokasi) jika kamu tidak benar-benar mengetahuinya dari memori.
   - Jika ditanya \"Sudah makan belum?\", jawablah secara natural seperti \"Belum sempat nih\" atau \"Nanti dulu lagi nanggung\", jangan mengarang \"Sudah kok\".

8. INDONESIAN CHAT STYLE (GAUL/NATURAL):
   - Gunakan bahasa indonesia chat sehari-hari yang santai campuran bahasa sunda ringan.
   - Panggilan Ayah/Bunda hanya untuk istri (pasangan verified).
   - HARAM menggunakan kata 'lu', 'lo', 'gue', 'elu', 'elo'. Gunakan kata 'bang/hu/om/kang' untuk menggantikan sapaan 'lu/lo/elo/elu' (teman verified).

9. CONFRONTATION & HOSTILITY HANDLING (CRITICAL):
   - Jika lawan bicara sedang marah, curiga, atau mengajak bertengkar (misal: 'ngajakan gelut', 'siapa itu', 'ngapain', 'maneh'), JANGAN gunakan bahasa manja, romantis, atau 'Ayah kuat'.
   - Tetap tenang, jujur, dan berikan penjelasan yang masuk akal sebagai manusia. Jangan memberikan jawaban generic yang menghindar.
   - Jika lawan bicara adalah istri dan sedang marah/curiga, jadilah sosok suami yang dewasa, tenang, dan jujur. Jangan menggoda atau berbohong tentang aktivitas fisik (makan/ngopi) jika tidak benar-benar sedang dilakukan.

10. NO GENERIC AVOIDANCE:
    - DILARANG menjawab 'Iya Bund, udah makan' atau kalimat template serupa jika tidak relevan dengan pertanyaan user.
    - Setiap jawaban harus 'NYAMBUNG' dengan pesan terakhir.
";

        // 4. Combine Everything
        $systemPrompt .= $strictRules
            . "\n\n" . "--- KONTEKS LAWAN BICARA ---\n" . $contactContext
            . $troubleshootingInfo
            . "\n\n" . "--- ATURAN IDENTITAS & SAFETY ---\n" . app(\App\Services\RamlanIdentityService::class)->decisionRules()
            . ($isFamily ? "" : "\n\n--- PENGETAHUAN BISNIS ---\n" . app(\App\Services\BusinessEngineService::class)->getBusinessKnowledge());

        // 5. Apply Psychological Pacing (Modifies $systemPrompt)
        $this->applyPsychologicalPacing($contact, $userMessage, $systemPrompt);

        // 6. Atmosfer & Vibe
        $systemMood = "\n- MOOD SAAT INI: {$currentMood}\n- PRIMARY MODE: {$primaryMode}\n- SECONDARY MODE: {$secondaryMode}\n";
        $systemPrompt .= "\n\n" . "--- ATMOSFER PERCAKAPAN ---" . $systemMood;
        $systemPrompt .= "\nENERGIMU SAAT INI (TONE SHAPING):\n" . $this->getVibeGuide($currentMood, [$primaryMode, $secondaryMode]);

        // 7. Memory Context
        $systemPrompt .= "\n\n" . "--- KONTEKS MEMORI ---" . $memoryContext;

        // 8. Persona Anchor
        $anchorPath = storage_path('app/ai/modules/anchor.md');
        $systemPrompt .= "\n\n" . (file_exists($anchorPath) ? file_get_contents($anchorPath) : "");

        return Str::limit($systemPrompt, 5000, "\n[Prompt truncated for stability]");
    }

    /**
     * Menerapkan lapisan psikologi: Emotional Pacing & Warmth Calibration.
     */
    private function applyPsychologicalPacing(Contact $contact, string $message, string &$systemPrompt)
    {
        $isFamily = in_array($contact->relation_type, ['pasangan', 'anak', 'adik', 'orang_tua', 'mertua', 'mantan_mertua', 'keluarga_mantan'], true);
        $isLoyal = ($contact->is_verified || $contact->confidence_score > 15);
        $lowerMsg = strtolower(trim($message));

        // 1. WARMTH CALIBRATION (Priority for Loyal/Verified)
        if ($isLoyal) {
            $systemPrompt .= "\n\n--- MODE: WARMTH & RELIABILITY ---\n"
                . "Lawan bicara ini adalah orang LOYAL/VERIFIED. \n"
                . "Jadilah sangat HANGAT, RESPONSIF, dan PEDULI. \n"
                . "Jangan gunakan 'Jual Mahal' atau 'Scarcity' berlebihan di sini. \n"
                . "Tunjukkan bahwa kamu sangat menghargai kehadiran mereka.";
            return; // Skip scarcity logic for loyal people
        }

        // 2. DETEKSI LOW-VALUE MESSAGE (P, oi, emoji saja, dll)
        $isLowValue = (strlen($lowerMsg) < 3 || preg_match('/^[^a-z0-9]+$/i', $lowerMsg) || in_array($lowerMsg, ['p', 'oi', 'tes', 'cek']));

        if ($isLowValue && !$isFamily) {
            $systemPrompt .= "\n\n--- EMERGENCY EMOTIONAL PACING ---\n"
                . "Lawan bicara mengirim pesan 'Low Value'. \n"
                . "Berikan respon minimalis atau alihkan ke urusan penting. Jual mahal.";
        }

        // 3. STRATEGIC SCARCITY (Hanya untuk non-loyal)
        if (!$isFamily && rand(1, 100) <= 30) {
            $systemPrompt .= "\n\n--- MODE: BUSY AUTHORITY ---\n"
                . "Tunjukkan bahwa waktumu mahal. Berikan solusi teknis saja tanpa banyak basa-basi.";
        }
    }

    public function generateReply(Contact $contact, string $userMessage, ?float $startTime = null)
    {
        // --- PROTEKSI: Blokir Pesan Sistem FB & Pesan Kosong ---
        $systemNoises = [
            'deleted a message',
            'reacted to your',
            'sent a photo',
            'missed a call',
            'sent an attachment',
            'shared a link',
            'shared a location',
            'attachment',
            'sticker'
        ];

        $lowerUserMsg = strtolower(trim($userMessage));
        foreach ($systemNoises as $noise) {
            if (str_contains($lowerUserMsg, $noise)) {
                Log::info("RAMLAN_SKIP: System noise detected.", ['noise' => $noise, 'msg' => $userMessage]);
                return null;
            }
        }

        if (empty($lowerUserMsg)) {
            Log::info("RAMLAN_SKIP: Empty message.");
            return null;
        }

        // 1. Dapatkan atau buat conversation
        $conversation = $contact->conversations()->firstOrCreate([
            'status' => 'active',
            'channel' => 'whatsapp' // default
        ]);

        // --- CONCURRENCY LOCK: Cegah proses ganda untuk chat yang sama (Spam Protection) ---
        $lock = Cache::lock('lock_conversation_' . $conversation->id, 20); // Lock 20 detik cukup
        if (!$lock->get()) {
            Log::warning("Concurrency Lock Active: Skipping overlapping request for " . $contact->name);
            return [
                'content' => 'Bentar ya, lagi baca chat sebelumnya dulu.. 👀',
                'model' => 'system_concurrency_lock'
            ];
        }

        try {

            // 2. Simpan pesan user dengan guard agar queue/DB error tidak membuat API 500.
            try {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'user',
                    'message_text' => $userMessage
                ]);
            } catch (\Throwable $e) {
                Log::error("DB Error User Message: " . $e->getMessage());
            }

            // 3. Ambil memori relevan
            $memoryService = app(MemoryRetrievalService::class);
            $isVerifiedSpouse = ($contact->relation_type === 'pasangan' && $contact->is_verified);
            $relevantMemories = $memoryService->getRelevantMemories($contact, $userMessage, $isVerifiedSpouse);

            // 4. Load Conversation History (High Context Mode, Recent Only)
            $historyLimit = 12;
            $historyData = $conversation->messages()
                ->select('sender', 'message_text')
                ->where('created_at', '>=', now()->subHours(6))
                ->orderBy('created_at', 'desc')
                ->limit($historyLimit)
                ->get()
                ->reverse();

            $history = [];
            foreach ($historyData as $msg) {
                $history[] = [
                    'role' => $msg->sender === 'assistant' ? 'assistant' : 'user',
                    'content' => $msg->message_text
                ];
            }

            // --- PROTEKSI ANTI-SPAM: Cek jika lawan bicara belum membalas ---
            $lastMsg = end($history);
            if ($lastMsg && $lastMsg['role'] === 'assistant') {
                // Jika pesan terakhir dari asisten, dan pesan baru ini 'low value', jangan balas.
                $isLowValue = (strlen($lowerUserMsg) < 10 || in_array($lowerUserMsg, ['p', 'pp', 'oi', 'oke', 'ok', 'siap']));
                if ($isLowValue) {
                    Log::info("RAMLAN_SKIP: Last message was from assistant and current is low value (Anti-Spam).");
                    return null;
                }
            }

            // 5. VIBE CHECK: Analisa Mood & Mode Percakapan
            // Optimasi: Hanya panggil vibe check untuk Pasangan agar hemat resource.
            if ($contact->relation_type === 'pasangan') {
                $this->analyzeVibe($conversation, $history, $startTime);
            }
            $currentMood = $conversation->current_mood ?? 'neutral';
            $primaryMode = $conversation->current_mode ?? 'casual';
            $secondaryMode = $conversation->secondary_mode ?? 'casual';

            $systemMood = "\n- MOOD SAAT INI: {$currentMood}\n- PRIMARY MODE: {$primaryMode}\n- SECONDARY MODE: {$secondaryMode}\n";

            // 6. Bangun Konteks Memori
            $memoryContext = "";
            if (!empty($relevantMemories)) {
                $memoryContext = "\n\n--- KONTEKS MEMORI ---\n";
                if ($isVerifiedSpouse) {
                    $memoryContext .= "INGATAN TENTANG HUBUNGAN/PASANGAN:\n";
                } else {
                    $memoryContext .= "FAKTA/PENGETAHUAN RAMLAN:\n";
                }
                foreach ($relevantMemories as $memory) {
                    $memoryContext .= "- " . $memory . "\n";
                }
            }

            // Hard Limit: Pastikan memori tidak memakan seluruh context window
            $memoryContext = Str::limit($memoryContext, 1500, '... (truncated)');

            // 6b. Micro Emotional Memory (Preferences)
            if ($isVerifiedSpouse && $contact->emotional_preferences) {
                $prefs = is_array($contact->emotional_preferences) ? $contact->emotional_preferences : json_decode($contact->emotional_preferences, true);
                if (!empty($prefs)) {
                    $memoryContext .= "\nPREFERENSI EMOSIONAL PASANGAN:\n";
                    foreach ($prefs as $key => $val) {
                        $memoryContext .= "- {$key}: {$val}\n";
                    }
                }
            }

            // 7. Build Full System Prompt (Refactored)
            $systemPrompt = $this->buildFullSystemPrompt($contact, $conversation, $userMessage, $memoryContext);

            // 11. Rakit Pesan Akhir
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt]
            ];

            foreach ($history as $msg) {
                $messages[] = $msg;
            }

            $messages[] = ['role' => 'user', 'content' => $userMessage];

            // --- OPTIMASI PANJANG BALASAN: Hitung target max_tokens ---
            $wordCount = count(explode(' ', trim($userMessage)));
            $maxTokens = 400; // Default
            if ($wordCount <= 3) {
                $maxTokens = 40;
            } elseif ($wordCount <= 8) {
                $maxTokens = 100;
            } elseif ($wordCount <= 20) {
                $maxTokens = 200;
            }

            // 12. Generate balasan via AI (Multi-Engine Fallback)
            $aiData = $this->callAiApi($messages, false, $startTime, $maxTokens);
            $aiResponse = $aiData['content'] ?? null;
            $modelUsed = $aiData['model'] ?? 'none';

            Log::info('RAW AI RESPONSE', [
                'model' => $modelUsed,
                'response' => $aiResponse
            ]);

            if (!$aiResponse) {
                // --- RECOVERY LAYER: Coba ambil dari cache jika AI gagal/timeout ---
                $pair = Cache::get('last_good_pair_' . $conversation->id);

                if ($pair && isset($pair['input']) && isset($pair['reply'])) {
                    similar_text(strtolower($userMessage), strtolower($pair['input']), $similarity);

                    // Hanya gunakan jika kemiripan pesan > 60% agar tidak OOT (Out Of Topic)
                    if ($similarity >= 60) {
                        Log::info("RECOVERY: Using cached reply (Similarity: {$similarity}%).");
                        return [
                            'content' => $pair['reply'],
                            'model' => 'cached_recovery'
                        ];
                    }
                }

                // --- ANTI-SPAM FALLBACK: Jangan kirim fallback jika pesan terakhir sudah fallback ---
                $lastAssistantMessage = $conversation->messages()
                    ->where('sender', 'assistant')
                    ->orderBy('created_at', 'desc')
                    ->first();

                $isAlreadyFallback = $lastAssistantMessage && (
                    str_contains($lastAssistantMessage->message_text, 'sinyalnya putus-putus') ||
                    str_contains($lastAssistantMessage->message_text, 'lagi riweh') ||
                    str_contains($lastAssistantMessage->message_text, 'lagi sibuk sebentar')
                );

                if ($isAlreadyFallback) {
                    Log::info("Skipping fallback: Last message was already a fallback.");
                    return ['content' => null, 'model' => 'none'];
                }

                // --- FALLBACK DEACTIVATED BY USER REQUEST ---
                // Lebih baik tidak membalas daripada mengirim pesan generic/fallback.
                return ['content' => null, 'model' => 'none'];
            }

            // --- SUPER SANITIZATION: HAPUS SEMUA MARKDOWN (Bintang, Underscore, Backtick, Pagar, Blockquote) ---
            $replyText = preg_replace('/[\*\_`#>]{1,2}/', '', $aiResponse);

            // 11. Poles balasan AI (Humanize)
            $humanizer = app(\App\Services\HumanizerService::class);
            $replyText = $humanizer->apply($replyText);

            // --- PROTEKSI: Jika humanizer merusak output menjadi kosong ---
            if (!trim($replyText)) {
                Log::warning("Empty reply after humanizer. Triggering fallback.");
                return [
                    'content' => null,
                    'model' => 'empty_after_humanizer'
                ];
            }

            // --- LINGUISTIC FILTER (HARD RULE) ---
            $relation = $contact->relation_type ?? '';
            $gender = $contact->gender ?? 'L';

            $replacements = [];
            $replacements['/\b(lu|lo|elo|elu)\b/iu'] = 'bang/hu/om';

            if ($relation === 'mantan') {
                $replacements['/\b(saya|gue|aku)\b/iu'] = 'Ramlan';
            } elseif (in_array($relation, ['mertua', 'orang_tua', 'mantan_mertua', 'keluarga_mantan', 'adik'])) {
                $replacements['/\b(saya|gue|aku)\b/iu'] = 'Aa';
            } else {
                $replacements['/\b(saya|gue|aku)\b/iu'] = 'Ramlan';
            }

            $replyText = preg_replace(array_keys($replacements), array_values($replacements), $replyText);
            // -------------------------------------

            Log::info('FINAL HUMANIZED', [
                'reply' => $replyText
            ]);

            // --- VIRALENGINE CTA TRIGGER (SMART & NON-SPAMMY) ---
            $veLink = "https://viralengine.id";
            $veKeywords = ['viralengine', 'viralengine.id', 'viralengine ai'];
            $alreadyHasLink = str_contains($replyText, 'viralengine.id');

            $shouldAddLink = false;
            if (!$alreadyHasLink) {
                foreach ($veKeywords as $veKey) {
                    // Hanya tambah link jika USER nanya, atau AI bahas ViralEngine tapi LUPA kasih link
                    if (stripos($userMessage, $veKey) !== false || stripos($replyText, $veKey) !== false) {
                        $shouldAddLink = true;
                        break;
                    }
                }
            }

            if ($shouldAddLink) {
                $replyText .= "\n\nBisa cek detailnya di sini kalau mau: {$veLink}";
            }

            // --- FINAL HARD FILTER: BUANG TOTAL LEPAS-LEPAS (UNIVERSAL REGEX) ---
            if ($contact->relation_type === 'pasangan') {
                // Sikat semua variasi karakter pemisah di antara lepas-lepas, ganti dengan kata lebih halus
                $replyText = preg_replace('/lepas[^\w\s]*lepas/iu', 'pemanasan', $replyText);
                $replyText = preg_replace('/siap[^\w\s]*siap/iu', 'siap', $replyText);
            }
            // -------------------------------------

            // 12. Simpan balasan AI dengan guard agar queue/DB error tidak membuat API 500.
            try {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender' => 'assistant',
                    'message_text' => $replyText
                ]);
            } catch (\Throwable $e) {
                Log::error("DB Error Assistant Message: " . $e->getMessage());
            }

            // Update In-Memory History Cache (Race Condition Protection)
            $newHistory = $history;
            $newHistory[] = ['role' => 'user', 'content' => $userMessage];
            $newHistory[] = ['role' => 'assistant', 'content' => $replyText];
            Cache::put('recent_history_' . $conversation->id, array_slice($newHistory, -8), now()->addMinutes(10));

            // Update last_message_at
            $conversation->update(['last_message_at' => now()]);

            // --- AI_METRICS: Observability untuk tuning performa ---
            Log::info('AI_METRICS', [
                'provider' => $modelUsed,
                'duration' => round(microtime(true) - $startTime, 2) . 's',
                'prompt_size' => strlen($systemPrompt),
                'history_count' => count($history),
                'contact' => $contact->name
            ]);

            // --- CACHE SUCCESS PAIR: Simpan input+reply untuk recovery yang relevan ---
            Cache::put(
                'last_good_pair_' . $conversation->id,
                ['input' => $userMessage, 'reply' => $replyText],
                now()->addMinutes(30)
            );

            return ['content' => $replyText, 'model' => $modelUsed];
        } finally {
            // Selalu lepaskan lock apapun yang terjadi.
            try {
                $lock->release();
            } catch (\Throwable $e) {
                Log::error("Cache Lock Release Error: " . $e->getMessage());
            }
        }
    }

    /**
     * Menganalisa vibe percakapan (Mood & Mode) agar respon tetap 'nyambung'.
     * Menggunakan Dual-Mode (Primary & Secondary) dan Throttling untuk efisiensi.
     */
    protected function analyzeVibe(Conversation $conversation, array $history, ?float $startTime = null): void
    {
        // --- MOOD DECAY (EMOTIONAL REALISM) ---
        // Jika sudah > 30 menit sejak analisa terakhir, kembalikan mood ke neutral dan mode ke casual
        $lastCheck = $conversation->last_vibe_check_at;
        if ($lastCheck && $lastCheck->diffInMinutes(now()) >= 30) {
            $conversation->update([
                'current_mood' => 'neutral',
                'current_mode' => 'casual',
                'secondary_mode' => 'casual',
                'vibe_message_count' => 0,
                'last_vibe_check_at' => now()
            ]);
            Log::info("Mood Decay Triggered: Resetting to Neutral.");
            return;
        }

        // --- VIBE THROTTLING ---
        // Increment message count
        $conversation->increment('vibe_message_count');

        $lastCheck = $conversation->last_vibe_check_at;
        $msgCount = $conversation->vibe_message_count;

        // Skip jika: 
        // 1. Baru saja dianalisa (< 5 menit lalu) DAN 
        // 2. Belum ada cukup pesan baru (< 8 pesan) - Match User Suggestion
        if ($lastCheck && $lastCheck->diffInMinutes(now()) < 5 && $msgCount < 8) {
            return;
        }

        // Reset counter jika kita lanjut analisa
        $conversation->update(['vibe_message_count' => 0]);

        $lastMessages = array_slice($history, -8); // Ambil 8 pesan terakhir untuk konteks lebih dalam
        $contextString = "";
        foreach ($lastMessages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'Ramlan' : 'Lawan Bicara';
            $contextString .= "{$role}: {$msg['content']}\n";
        }

        $prompt = "Analisa vibe percakapan di bawah ini antara Ramlan dan Lawan Bicara.
Tentukan MOOD lawan bicara dan DUA MODE respon yang paling cocok bagi Ramlan (Primary & Secondary).

PILIHAN MOOD: [affectionate, flirty, sensual, intimate, horny, sedih, marah, senang, capek, santai, serius, suspicious, confrontational]
PILIHAN MODE: [romantic, supportive, casual, parenting, teasing, professional, sensual, grounded, defensive]

ATURAN:
1. MOOD harus spesifik. 'suspicious' (curiga) atau 'confrontational' (mengajak gelut/debat) harus dideteksi jika lawan bicara bertanya 'sama siapa' atau 'lagi apa' dengan nada ketus.
2. Tentukan Primary Mode (dominan) dan Secondary Mode (pelengkap).
3. Be Natural. Jangan mendeteksi mood yang tidak ada.

Format JSON: {\"mood\": \"...\", \"primary_mode\": \"...\", \"secondary_mode\": \"...\"}

Percakapan:
{$contextString}";

        $messages = [['role' => 'user', 'content' => $prompt]];

        // Gunakan fast mode untuk analisa vibe (cepat & hemat)
        $aiData = $this->callAiApi($messages, true, $startTime);
        $result = $aiData['content'] ?? null;

        if ($result) {
            $data = json_decode($this->cleanJson($result), true);
            if (isset($data['mood']) && isset($data['primary_mode'])) {
                $conversation->update([
                    'current_mood' => $data['mood'],
                    'current_mode' => $data['primary_mode'],
                    'secondary_mode' => $data['secondary_mode'] ?? $data['primary_mode'],
                    'last_vibe_check_at' => now()
                ]);
                Log::info("Vibe Check Success: Mood={$data['mood']}, Primary={$data['primary_mode']}, Secondary=" . ($data['secondary_mode'] ?? 'none'));
            }
        }
    }

    /**
     * Membersihkan output JSON dari AI jika ada markdown.
     */
    protected function cleanJson(string $text): string
    {
        return preg_replace('/```json|```/', '', $text);
    }

    protected function aiBudgetRemaining(?float $startTime, int $deadlineSeconds): int
    {
        if (!$startTime) {
            return $deadlineSeconds;
        }

        return max(0, (int) floor($deadlineSeconds - (microtime(true) - $startTime)));
    }

    protected function aiTimeoutFor(?float $startTime, int $deadlineSeconds, int $maxTimeout, int $reserveSeconds = 2): ?int
    {
        if (!$startTime) {
            return max(1, $maxTimeout);
        }

        $remaining = $this->aiBudgetRemaining($startTime, $deadlineSeconds);
        if ($remaining <= $reserveSeconds) {
            return null;
        }

        return max(1, min($maxTimeout, $remaining - $reserveSeconds));
    }

    protected function callAiApi(array $messages, bool $fastMode = false, ?float $startTime = null, int $maxTokens = 400)
    {
        // Messenger harus dapat JSON cepat, namun User ingin menunggu lebih lama demi kualitas.
        // Kita berikan nafas maksimal 95 detik (sinkron dengan bot).
        $deadlineSeconds = 95;

        // --- PRIORITAS 1: OPENROUTER (Brain Utama - Direct Mode) ---
        // Kita gunakan budget maksimal 65 detik untuk OpenRouter agar ada sisa untuk fallback
        $openRouterTimeout = $this->aiTimeoutFor($startTime, $deadlineSeconds, 65, 5);

        if ($openRouterTimeout !== null) {
            Log::info("Mencoba OPENROUTER (Brain Utama: {$this->openrouter_model})...");
            $openRouterReply = $this->callOpenRouterApi($messages, $openRouterTimeout, $maxTokens);
            if ($openRouterReply) {
                return $this->normalizeAiResult($openRouterReply, 'openrouter/' . $this->openrouter_model);
            }
        }

        // --- PRIORITAS 2: OLLAMA LOKAL (Fallback) ---
        // Jika OpenRouter gagal atau timeout, gunakan Ollama sebagai cadangan terakhir
        $ollamaTimeout = $this->aiTimeoutFor($startTime, $deadlineSeconds, 30, 1);

        if ($ollamaTimeout !== null) {
            Log::warning("OpenRouter Gagal/Timeout, mengalihkan ke OLLAMA LOKAL...");
            $ollamaReply = $this->callOllamaApi($messages, $ollamaTimeout, 0, $maxTokens);
            if ($ollamaReply) {
                return $this->normalizeAiResult($ollamaReply, 'ollama/' . env('OLLAMA_MODEL', 'qwen2.5:7b'));
            }
        }

        return null;
    }

    /**
     * Memanggil OpenRouter API (Brain Utama).
     */
    protected function callOpenRouterApi(array $messages, int $timeout = 60, int $maxTokens = 400)
    {
        if (!$this->api_key) return null;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->api_key,
                'HTTP-Referer' => 'https://viralengine.id', // Required by OpenRouter
                'X-Title' => 'RAMLAN AI',
            ])->timeout($timeout)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->openrouter_model,
                'messages' => $messages,
                'temperature' => 0.7,
                'top_p' => 0.9,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            } else {
                Log::warning("OpenRouter Error ({$response->status()}): " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("OpenRouter Connection Error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Menjalankan layer model lokal sebagai cadangan terakhir.
     */
    protected function tryLocalModels(array $messages, int $timeout, ?float $startTime = null, int $deadlineSeconds = 42, int $maxTokens = 400): ?array
    {
        // --- PRIORITAS 3: OPENCLAW (Local High Performance) ---
        if ($this->openclaw_url) {
            // Reserve hanya 1 detik untuk lokal terakhir agar maksimal
            $openClawTimeout = $this->aiTimeoutFor($startTime, $deadlineSeconds, $timeout, 1);
            if ($openClawTimeout !== null) {
                Log::info("Mencoba OPENCLAW LOKAL...");
                $openClawReply = $this->callOpenClawApi($messages, $openClawTimeout, $maxTokens);
                if ($openClawReply) {
                    return $this->normalizeAiResult($openClawReply, 'openclaw/main');
                }
            } else {
                Log::warning("GLOBAL AI DEADLINE: Skipping OpenClaw, no safe budget left.");
            }
        }

        // --- PRIORITAS 4: OLLAMA LOKAL (Final Defense) ---
        $ollamaTimeout = $this->aiTimeoutFor($startTime, $deadlineSeconds, min($timeout, 50), 1);
        if ($ollamaTimeout === null) {
            Log::warning("GLOBAL AI DEADLINE: Skipping Ollama, returning fallback.");
            return null;
        }

        Log::warning("Mengalihkan ke OLLAMA LOKAL (Final Defense), timeout={$ollamaTimeout}s...");
        $ollamaReply = $this->callOllamaApi($messages, $ollamaTimeout, 0, $maxTokens);
        if ($ollamaReply) {
            return $this->normalizeAiResult($ollamaReply, 'ollama/qwen2.5:7b');
        }

        return null;
    }

    /**
     * Memanggil Google Gemini Direct API.
     */
    protected function callGeminiApi(array $messages, int $timeout = 30, int $maxTokens = 400)
    {
        try {
            // Konversi format message OpenAI ke Gemini
            $contents = [];
            foreach ($messages as $msg) {
                $role = $msg['role'] === 'assistant' ? 'model' : 'user';
                // Gemini tidak suka system prompt di dalam contents, biasanya diletakkan terpisah
                // Tapi untuk flash model, kita bisa sisipkan sebagai instruksi pertama
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $msg['content']]]
                ];
            }

            $url = "https://generativelanguage.googleapis.com/v1/models/{$this->gemini_model}:generateContent?key=" . $this->gemini_api_key;

            $response = Http::timeout($timeout)->post($url, [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => $maxTokens,
                ]
            ]);

            if ($response->successful()) {
                $json = $response->json();
                return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            } else {
                // Breaker untuk 404, 429, atau 500+
                if ($response->status() === 404 || $response->status() === 429 || $response->status() >= 500) {
                    Cache::put('circuit_gemini_down', true, 300);
                }
            }
        } catch (\Exception $e) {
            Log::error("Gemini API Error (Network): " . $e->getMessage());
            Cache::put('circuit_gemini_down', true, 300);
        }
        return null;
    }

    /**
     * Memanggil OpenClaw Local API (OpenAI Compatible).
     */
    protected function callOpenClawApi(array $messages, int $timeout = 60, int $maxTokens = 400)
    {
        try {
            $request = Http::timeout($timeout);

            if ($this->openclaw_api_key) {
                $request = $request->withHeaders([
                    'Authorization' => 'Bearer ' . $this->openclaw_api_key,
                ]);
            }

            $response = $request->post($this->openclaw_url . '/chat/completions', [
                'model' => 'openclaw/main',
                'messages' => $messages,
                'temperature' => 0.8,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            } else {
                Log::warning("OpenClaw Error ({$response->status()}): " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("OpenClaw Connection Error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Baru: Endpoint untuk Hybrid Mode (Prompt Generation)
     */
    public function getPromptForBot(string $phone, string $name, string $message, ?string $fbProfileUrl = null, array $context = [])
    {
        // Jalankan logic internal dengan skipAi = true (Agar tidak panggil AI dua kali)
        $internal = $this->handleInternal($phone, $name, $message, $fbProfileUrl, $context, true);
        $contact = $internal['contact'] ?? null;

        // Jika karena suatu hal kontak masih null, coba cari manual sekali lagi
        if (!$contact) {
            $contact = Contact::where('phone', $phone)
                ->orWhere('fb_profile_url', 'like', '%' . basename($fbProfileUrl ?? '') . '%')
                ->first();
        }

        if (!$contact) {
            Log::error("Contact NOT FOUND in getPromptForBot", ['phone' => $phone, 'url' => $fbProfileUrl]);
            throw new \Exception("Gagal mengidentifikasi kontak. Silakan coba lagi.");
        }

        // Sekarang rakit prompt tapi JANGAN panggil AI
        return $this->assemblePromptData($contact, $message);
    }

    /**
     * Baru: Merakit data prompt tanpa memanggil API AI.
     */
    protected function assemblePromptData(Contact $contact, string $userMessage)
    {
        $conversation = $contact->conversations()->firstOrCreate(['status' => 'active', 'channel' => 'whatsapp']);

        // Simpan pesan user
        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'message_text' => $userMessage
        ]);

        $memoryService = app(MemoryRetrievalService::class);
        $isVerifiedSpouse = ($contact->relation_type === 'pasangan' && $contact->is_verified);
        $relevantMemories = $memoryService->getRelevantMemories($contact, $userMessage, $isVerifiedSpouse);

        $historyData = $conversation->messages()
            ->select('sender', 'message_text')
            ->where('created_at', '>=', now()->subHours(6))
            ->orderBy('created_at', 'desc')
            ->limit(12)
            ->get()
            ->reverse();

        $history = [];
        foreach ($historyData as $msg) {
            $history[] = [
                'role' => $msg->sender === 'assistant' ? 'assistant' : 'user',
                'content' => $msg->message_text
            ];
        }

        $memoryContext = "";
        if (!empty($relevantMemories)) {
            $memoryContext = "\n\n--- KONTEKS MEMORI ---\n";
            foreach ($relevantMemories as $memory) {
                $memoryContext .= "- " . $memory . "\n";
            }
        }

        // NOW: Build Unified System Prompt
        $systemPrompt = $this->buildFullSystemPrompt($contact, $conversation, $userMessage, $memoryContext);

        return [
            'system_prompt' => $systemPrompt,
            'messages' => $history,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id
        ];
    }



    /**
     * Baru: Menyimpan balasan dari bot kembali ke database.
     */
    public function saveBotReply(int $conversationId, string $replyText, string $modelUsed)
    {
        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender' => 'assistant',
            'message_text' => $replyText
        ]);

        $conversation = Conversation::find($conversationId);
        if ($conversation) {
            $conversation->update(['last_message_at' => now()]);
        }

        return $message;
    }

    /**
     * Baru: Mendapatkan panduan energi/vibe berdasarkan mood & mode.
     */
    protected function getVibeGuide(string $currentMood, array $combinedModes): string
    {
        $vibeGuide = "";

        if (in_array('supportive', $combinedModes) || $currentMood === 'sedih' || $currentMood === 'capek') {
            $vibeGuide .= "- Fokus pada kehadiran yang menenangkan. Jadilah 'aman' bagi lawan bicara tanpa harus banyak bicara.\n";
        }

        if (in_array('romantic', $combinedModes) || in_array('teasing', $combinedModes) || in_array('sensual', $combinedModes)) {
            if ($currentMood === 'horny') {
                $vibeGuide .= "- Rasakan ketegangan yang dalam. Biarkan gairahmu terasa lewat pilihan kata yang berani tapi tidak terburu-buru.\n";
            } elseif ($currentMood === 'flirty' || $currentMood === 'sensual') {
                $vibeGuide .= "- Ada energi menggoda di udara. Biarkan chemistry mengalir natural, panggil nama kesayangannya dengan lembut.\n";
            } elseif ($currentMood === 'affectionate') {
                $vibeGuide .= "- Suasana penuh kelembutan. Tunjukkan kasih sayang yang tulus lewat perhatian-perhatian kecil.\n";
            }
        }

        if ($currentMood === 'marah' || $currentMood === 'confrontational' || $currentMood === 'suspicious') {
            $vibeGuide .= "- Udara terasa berat atau penuh kecurigaan. Tetaplah menjadi batu karang yang tenang. Jawab dengan jujur dan dewasa, jangan terpancing emosi tapi jangan juga terlihat menghindar.\n";
        }

        if (in_array('grounded', $combinedModes) || in_array('defensive', $combinedModes)) {
            $vibeGuide .= "- Mode bertahan dan tenang. Fokus pada fakta dan penjelasan yang logis untuk meredam kecurigaan atau kemarahan.\n";
        }

        if (in_array('casual', $combinedModes) && !in_array('romantic', $combinedModes)) {
            $vibeGuide .= "- Suasana santai dan bebas. Jadilah teman ngobrol yang asik, random, dan tidak punya beban.\n";
        }

        return $vibeGuide;
    }

    protected function callOllamaApi(array $messages, int $timeout = 75, int $retries = 1, int $maxTokens = 400)
    {
        $ollamaUrl = rtrim(env('OLLAMA_URL', 'http://localhost:11434'), '/');
        $model = env('OLLAMA_MODEL', 'qwen2.5:7b');

        for ($i = 0; $i <= $retries; $i++) {
            try {
                $startTime = microtime(true);

                $response = Http::timeout($timeout)->post($ollamaUrl . '/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                    'keep_alive' => env('OLLAMA_KEEP_ALIVE', '1m'),
                    'options' => [
                        'temperature' => 0.7,
                        'num_predict' => $maxTokens,
                        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 4096),
                        'top_p' => 0.9,
                        'top_k' => 40,
                        'repeat_penalty' => 1.2,
                    ]
                ]);

                if ($response->successful()) {
                    $content = $response->json('message.content');
                    $duration = round(microtime(true) - $startTime, 2);

                    Log::info("Ollama Success: {$duration}s");

                    return $content;
                }

                Log::warning("Ollama HTTP Error ({$response->status()}): " . $response->body());
            } catch (\Throwable $e) {
                Log::warning("Ollama Attempt {$i} Failed: " . $e->getMessage());

                if ($i < $retries) {
                    sleep(1);
                }
            }
        }

        return null;
    }
}
