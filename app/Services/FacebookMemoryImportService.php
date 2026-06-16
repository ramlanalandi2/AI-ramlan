<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Memory;
use Illuminate\Support\Facades\Http;

class FacebookMemoryImportService
{
    public function importPageConversations(int $limit = 10): array
    {
        $pageId = config('services.facebook.page_id');
        $token = config('services.facebook.page_access_token');

        if (empty($pageId) || empty($token)) {
            return [
                'success' => false,
                'error' => 'Facebook credentials not set.'
            ];
        }

        $response = Http::get("https://graph.facebook.com/v20.0/{$pageId}/conversations", [
            'access_token' => $token,
            'limit' => $limit,
            'fields' => 'id,updated_time,participants'
        ]);

        if (!$response->successful()) {
            return [
                'success' => false,
                'error' => $response->json()
            ];
        }

        $imported = 0;

        foreach ($response->json('data', []) as $fbConversation) {
            $imported += $this->importConversationMessages($fbConversation['id']);
        }

        return [
            'success' => true,
            'imported_messages' => $imported
        ];
    }

    public function importConversationMessages(string $fbConversationId): int
    {
        $token = config('services.facebook.page_access_token');

        $response = Http::get("https://graph.facebook.com/v20.0/{$fbConversationId}/messages", [
            'access_token' => $token,
            'limit' => 50,
            'fields' => 'id,message,from,to,created_time'
        ]);

        if (!$response->successful()) {
            return 0;
        }

        $count = 0;

        foreach ($response->json('data', []) as $fbMessage) {
            $messageText = $fbMessage['message'] ?? null;

            if (!$messageText) {
                continue;
            }

            $fbSenderId = $fbMessage['from']['id'] ?? 'unknown';
            $fbSenderName = $fbMessage['from']['name'] ?? 'Facebook User';

            $contact = Contact::firstOrCreate(
                ['phone' => 'fb_' . $fbSenderId],
                [
                    'name' => $fbSenderName,
                    'relation_type' => 'facebook'
                ]
            );

            $conversation = Conversation::firstOrCreate([
                'contact_id' => $contact->id,
                'channel' => 'facebook',
                'status' => 'active'
            ]);

            Message::firstOrCreate(
                ['wa_message_id' => 'fb_' . $fbMessage['id']],
                [
                    'conversation_id' => $conversation->id,
                    'sender' => 'user',
                    'message_text' => $messageText,
                    'created_at' => $fbMessage['created_time'] ?? now(),
                ]
            );

            // Extract only important facts via AI
            app(MemoryExtractorService::class)->extract($contact, $messageText);

            $count++;
        }

        return $count;
    }
}
