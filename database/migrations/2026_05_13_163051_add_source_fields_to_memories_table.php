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
        Schema::table('memories', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('memory_type');
            $table->string('source_message_id')->nullable()->after('source');
            $table->json('meta')->nullable()->after('source_message_id');

            $table->index(['source', 'source_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex(['source', 'source_message_id']);
            $table->dropColumn(['source', 'source_message_id', 'meta']);
        });
    }
};
