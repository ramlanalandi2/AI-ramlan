<?php

namespace App\Http\Controllers;

use App\Services\AiReplyService;
use App\Services\HumanizerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function verify(Request $request)
    {
        if (
            $request->get('hub_mode') === 'subscribe' &&
            $request->get('hub_verify_token') === config('services.whatsapp.verify_token')
        ) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('Invalid verify token', 403);
    }

    public function receive(Request $request)
    {
        Log::info('WhatsApp webhook received', $request->all());

        $entry = $request->input('entry.0.changes.0.value');

        // Check if there are messages in the payload
        $message = $entry['messages'][0] ?? null;

        if (!$message || ($message['type'] ?? null) !== 'text') {
            return response()->json(['status' => 'ignored']);
        }

        $from = $message['from'];
        $text = $message['text']['body'] ?? '';
        $name = $entry['contacts'][0]['profile']['name'] ?? 'User';

        if (!$from || !$text) {
            return response()->json(['status' => 'empty']);
        }

        // --- OWNER CONTROL / KILL SWITCH ---
        $ownerPhone = config('services.owner_phone');

        if ($from === $ownerPhone) {
            $cmd = trim(strtolower($text));
            if ($cmd === '/ai off') {
                \App\Models\AiSetting::setValue('auto_reply', 'off');
                $this->sendMessage($from, 'oke, auto reply saya matikan dulu.');
                return response()->json(['status' => 'ai_off']);
            }

            if ($cmd === '/ai on') {
                \App\Models\AiSetting::setValue('auto_reply', 'on');
                $this->sendMessage($from, 'oke, auto reply saya aktifkan lagi.');
                return response()->json(['status' => 'ai_on']);
            }

            if ($cmd === '/status') {
                $status = \App\Models\AiSetting::getValue('auto_reply', 'on');
                $this->sendMessage($from, "status auto reply: {$status}");
                return response()->json(['status' => 'status_sent']);
            }
        }

        $autoReply = \App\Models\AiSetting::getValue('auto_reply', 'on');

        if ($autoReply !== 'on') {
            Log::info("Auto reply is OFF. Skipping message from {$from}.");
            return response()->json(['status' => 'auto_reply_off']);
        }
        // ------------------------------------

        // Process message via AI RAMLAN
        $replyData = app(AiReplyService::class)->handle(
            phone: $from,
            name: $name,
            message: $text
        );

        $finalReply = is_array($replyData)
            ? ($replyData['ai_reply'] ?? 'sebentar saya cek dulu')
            : $replyData;

        // Apply humanization delay
        $delay = app(HumanizerService::class)->calculateTypingDelay($finalReply);

        // Cap delay for response time safety
        sleep(min($delay, 8));

        // Send reply back via WhatsApp Cloud API
        $this->sendMessage($from, $finalReply);

        return response()->json(['status' => 'ok']);
    }

    private function sendMessage(string $to, string $text): void
    {
        $version = config('services.whatsapp.api_version');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.access_token');

        if (empty($token) || empty($phoneNumberId)) {
            Log::warning('WhatsApp sendMessage failed: Credentials not set.');
            return;
        }

        Http::withToken($token)->post(
            "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $text,
                ],
            ]
        );
    }
}
