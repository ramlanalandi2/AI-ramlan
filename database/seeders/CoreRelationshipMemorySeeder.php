<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Memory;
use Illuminate\Database\Seeder;

class CoreRelationshipMemorySeeder extends Seeder
{
    /**
     * Seed Ramlan's permanent relationship identity memories.
     */
    public function run(): void
    {
        $memories = [
            [
                'source_message_id' => 'core_relationship_spouse_identity',
                'content' => 'Identitas permanen: Resti, Mayang, dan Resti Dewi adalah istri Ramlan.',
                'relation' => 'pasangan',
                'aliases' => ['resti', 'mayang', 'resti dewi'],
            ],
            [
                'source_message_id' => 'core_relationship_ex_spouse_identity',
                'content' => 'Identitas permanen: Caca, Baby Caa, Chaa, dan Anisa adalah mantan istri Ramlan.',
                'relation' => 'mantan',
                'aliases' => ['caca', 'baby caa', 'babycaa', 'chaa', 'anisa'],
            ],
            [
                'source_message_id' => 'core_relationship_sister_identity',
                'content' => 'Identitas permanen: Sri adalah adik perempuan Ramlan.',
                'relation' => 'adik',
                'aliases' => ['sri'],
            ],
            [
                'source_message_id' => 'core_relationship_addressing_rule',
                'content' => 'Aturan identitas: panggilan A atau Aa tidak cukup untuk menentukan hubungan. Panggilan itu bisa dipakai adik perempuan, teman laki-laki lebih muda, mantan mertua, mertua sekarang, atau orang tua.',
                'relation' => 'identity_rule',
                'aliases' => ['a', 'aa'],
            ],
            [
                'source_message_id' => 'core_relationship_mentioned_people_rule',
                'content' => 'Aturan identitas: nama yang disebut dalam pesan seperti Arfa, Resti, Caca, Anisa, Mayang, atau Sri adalah orang yang sedang dibahas, bukan otomatis identitas lawan bicara.',
                'relation' => 'identity_rule',
                'aliases' => ['arfa', 'resti', 'caca', 'anisa', 'mayang', 'sri'],
            ],
        ];

        foreach ($memories as $memory) {
            Memory::updateOrCreate(
                [
                    'source' => 'manual_core_identity',
                    'source_message_id' => $memory['source_message_id'],
                ],
                [
                    'contact_id' => null,
                    'memory_type' => 'identity',
                    'content' => $memory['content'],
                    'importance' => 10,
                    'meta' => [
                        'relation' => $memory['relation'],
                        'aliases' => $memory['aliases'],
                    ],
                ]
            );
        }

        $this->verifyMatchingContacts(['resti', 'mayang', 'resti dewi'], 'pasangan');
        $this->verifyMatchingContacts(['caca', 'baby caa', 'babycaa', 'chaa', 'anisa'], 'mantan');
        $this->verifyMatchingContacts(['sri'], 'adik');
        $this->verifyMatchingProfile('100014771134074', 'adik', 'P', 'Profile ID cocok dengan adik perempuan.');
    }

    private function verifyMatchingContacts(array $aliases, string $relation): void
    {
        foreach (Contact::all() as $contact) {
            $name = trim(preg_replace('/\s+/', ' ', strtolower((string) $contact->name)));

            if ($name === '' || str_contains($name, 'pay vidiads')) {
                continue;
            }

            foreach ($aliases as $alias) {
                if ($name === $alias || (strlen($name) <= 24 && str_contains($name, $alias))) {
                    $contact->update([
                        'relation_type' => $relation,
                        'gender' => 'P',
                        'is_verified' => true,
                        'identity_locked' => true,
                        'verified_by' => 'manual_core_identity',
                        'identity_notes' => 'Dikunci oleh CoreRelationshipMemorySeeder berdasarkan alias nama.',
                        'confidence_score' => 100,
                    ]);

                    break;
                }
            }
        }
    }

    private function verifyMatchingProfile(string $profileNeedle, string $relation, string $gender, string $notes): void
    {
        Contact::where('fb_profile_url', 'like', '%' . $profileNeedle . '%')->update([
            'relation_type' => $relation,
            'gender' => $gender,
            'is_verified' => true,
            'identity_locked' => true,
            'verified_by' => 'manual_core_identity',
            'identity_notes' => $notes,
            'confidence_score' => 100,
        ]);
    }
}
