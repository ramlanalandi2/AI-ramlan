<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportFacebookMemories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ramlan:import-facebook-memories';
    protected $description = 'Import Facebook Page Messenger conversations into AI RAMLAN memories';

    public function handle()
    {
        $this->info('Starting Facebook Import...');
        
        $result = app(\App\Services\FacebookMemoryImportService::class)
            ->importPageConversations(20);

        if ($result['success']) {
            $this->info("Imported " . $result['imported_messages'] . " messages successfully.");
        } else {
            $this->error("Failed: " . json_encode($result['error']));
        }
    }
}
