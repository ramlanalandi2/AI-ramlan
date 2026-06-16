<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\AiReplyService;

class TelegramBotController extends Controller
{
    protected AiReplyService $aiService;
    protected string $botToken;

    public function __construct(AiReplyService $aiService)
    {
        $this->aiService = $aiService;
        $this->botToken = config('services.telegram.bot_token');
    }

    public function handle(Request $request)
    {
        $update = $request->all();

        if (!isset($update['message'])) {
            return response()->json(['status' => 'no_message']);
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $senderName = $message['from']['first_name'] ?? 'User Telegram';
        
        if (isset($message['from']['last_name'])) {
            $senderName .= ' ' . $message['from']['last_name'];
        }

        // --- PROTEKSI: Jangan balas jika pesan kosong atau bukan teks ---
        if (empty($text)) {
            return response()->json(['status' => 'empty_text']);
        }

        Log::info("Telegram Message received from $senderName: $text");

        // --- PROSES VIA AI SERVICE ---
        $phone = 'telegram_' . $chatId;
        
        $result = $this->aiService->handle(
            $phone,
            $senderName,
            $text,
            "https://t.me/" . ($message['from']['username'] ?? $chatId)
        );

        $aiReply = $result['ai_reply'];

        if ($aiReply) {
            // 1. Kirim status "Typing..." agar terlihat manusiawi
            $this->sendChatAction($chatId, 'typing');

            // 2. Jeda acak (3-7 detik) seolah sedang mengetik
            $delay = rand(3, 7);
            sleep($delay);

            $this->sendMessage($chatId, $aiReply);
        }

        return response()->json(['status' => 'success']);
    }

    protected function sendMessage($chatId, $text)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        $response = Http::post($url, [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ]);

        if (!$response->successful()) {
            Log::error("Telegram Send Error: " . $response->body());
        }

        return $response;
    }

    protected function sendChatAction($chatId, $action)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendChatAction";

        return Http::post($url, [
            'chat_id' => $chatId,
            'action' => $action
        ]);
    }

    /**
     * Helper untuk set webhook secara manual (bisa diakses via browser sekali saja).
     */
    public function setWebhook(Request $request)
    {
        $webhookUrl = $request->input('url');
        if (!$webhookUrl) {
            return "URL webhook kosong! Contoh: /api/telegram/set-webhook?url=https://domain.com/api/telegram/webhook";
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/setWebhook";
        $response = Http::post($url, ['url' => $webhookUrl]);

        return $response->json();
    }
}
