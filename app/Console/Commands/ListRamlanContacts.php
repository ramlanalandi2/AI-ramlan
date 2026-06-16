<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ListRamlanContacts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ramlan:contacts {--relation= : Filter berdasarkan tipe hubungan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all identified contacts and their relationship status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $relation = $this->option('relation');
        
        $query = \App\Models\Contact::query();
        
        if ($relation) {
            $query->where('relation_type', $relation);
        }

        $contacts = $query->orderByDesc('is_verified')
                         ->orderByDesc('confidence_score')
                         ->get();

        if ($contacts->isEmpty()) {
            $this->info("Belum ada kontak yang terdeteksi.");
            return;
        }

        $headers = ['ID', 'Name', 'Relation', 'Verified', 'Score', 'Profile ID/URL'];
        
        $data = $contacts->map(function ($c) {
            return [
                $c->id,
                $c->name,
                strtoupper($c->relation_type),
                $c->is_verified ? 'YES' : 'NO',
                $c->confidence_score,
                $c->fb_profile_url ?? 'N/A'
            ];
        });

        $this->table($headers, $data);
    }
}
