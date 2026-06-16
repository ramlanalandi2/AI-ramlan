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
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('current_mood')->default('neutral')->after('status');
            $table->string('current_mode')->default('casual')->after('current_mood');
            $table->timestamp('last_vibe_check_at')->nullable()->after('current_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['current_mood', 'current_mode', 'last_vibe_check_at']);
        });
    }
};
