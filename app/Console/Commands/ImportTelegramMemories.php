<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Memory;
use App\Services\MemoryExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportTelegramMemories extends Command
{
    protected $signature = 'ramlan:import-telegram-memories {file=storage/app/imports/telegram/result.json}';
    protected $description = 'Import Telegram exported JSON chat into AI RAMLAN memories';

    public function handle()
    {
        $file = base_path($this->argument('file'));

        if (!File::exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $json = json_decode(File::get($file), true);

        if (!$json) {
            $this->error('Invalid Telegram JSON');
            return self::FAILURE;
        }

        $chatName = $json['name'] ?? 'Telegram Contact';

        $contact = Contact::firstOrCreate(
            ['phone' => 'telegram_' . md5($chatName)],
            [
                'name' => $chatName,
                'relation_type' => 'telegram'
            ]
        );

        $conversation = Conversation::firstOrCreate([
            'contact_id' => $contact->id,
            'channel' => 'telegram',
            'status' => 'active'
        ]);

        $count = 0;

        foreach ($json['messages'] ?? [] as $tgMessage) {
            if (($tgMessage['type'] ?? null) !== 'message') {
                continue;
            }

            $text = $tgMessage['text'] ?? '';

            if (is_array($text)) {
                $text = collect($text)->map(function ($part) {
                    return is_array($part) ? ($part['text'] ?? '') : $part;
                })->implode('');
            }

            $text = trim($text);

            if ($text === '') {
                continue;
            }

            $sourceId = 'tg_' . ($tgMessage['id'] ?? md5($text));

            Message::firstOrCreate(
                ['wa_message_id' => $sourceId],
                [
                    'conversation_id' => $conversation->id,
                    'sender' => 'user',
                    'message_text' => $text,
                    'created_at' => $tgMessage['date'] ?? now(),
                ]
            );

            // Extract only important facts via AI
            app(MemoryExtractorService::class)->extract($contact, $text);

            $count++;
        }

        $this->info("Imported {$count} Telegram messages.");
        return self::SUCCESS;
    }
}
