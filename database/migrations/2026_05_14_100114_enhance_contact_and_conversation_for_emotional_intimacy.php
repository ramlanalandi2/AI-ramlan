<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('emotional_preferences')->nullable()->after('relation_type');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('vibe_decay_at')->nullable()->after('last_vibe_check_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('emotional_preferences');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('vibe_decay_at');
        });
    }
};
