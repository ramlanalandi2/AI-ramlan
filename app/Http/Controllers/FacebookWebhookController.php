<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AiReplyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookWebhookController extends Controller
{
    protected $aiService;

    public function __construct(AiReplyService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Verifikasi Webhook dari Meta (GET)
     */
    public function verify(Request $request)
    {
        $verifyToken = 'ai_ramlan_verify_123'; // Samakan dengan WhatsApp untuk kemudahan
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                return response($challenge, 200);
            }
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle Pesan Masuk (POST)
     */
    public function handle(Request $request)
    {
        Log::info('Facebook Webhook Received', $request->all());

        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            $messaging = $entry['messaging'] ?? [];
            foreach ($messaging as $event) {
                // Pastikan ada pesan teks dan bukan dari bot sendiri
                if (isset($event['message']['text']) && !isset($event['message']['is_echo'])) {
                    $senderPsid = $event['sender']['id'];
                    $userMessage = $event['message']['text'];

                    // 1. Dapatkan Nama User (Opsional, perlu permission user_profile)
                    $userName = $this->getUserName($senderPsid);

                    // 2. Kirim ke AI RAMLAN
                    $aiResponse = $this->aiService->handle($senderPsid, $userName, $userMessage);

                    // 3. Kirim balik ke Messenger
                    $this->sendToMessenger($senderPsid, $aiResponse['ai_reply']);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Mengambil Nama User dari Graph API
     */
    private function getUserName($psid)
    {
        try {
            $token = env('FACEBOOK_PAGE_ACCESS_TOKEN');
            $response = Http::get("https://graph.facebook.com/{$psid}", [
                'fields' => 'first_name,last_name',
                'access_token' => $token
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return ($data['first_name'] ?? 'User') . ' ' . ($data['last_name'] ?? '');
            }
        } catch (\Exception $e) {
            Log::error('Failed to get Facebook user name: ' . $e->getMessage());
        }

        return 'User Messenger';
    }

    /**
     * Mengirim pesan balasan ke Messenger
     */
    private function sendToMessenger($psid, $message)
    {
        $token = env('FACEBOOK_PAGE_ACCESS_TOKEN');
        
        $response = Http::post("https://graph.facebook.com/v19.0/me/messages?access_token={$token}", [
            'recipient' => ['id' => $psid],
            'message' => ['text' => $message],
            'messaging_type' => 'RESPONSE'
        ]);

        if (!$response->successful()) {
            Log::error('Facebook Send Message Failed', $response->json());
        }
    }
}
