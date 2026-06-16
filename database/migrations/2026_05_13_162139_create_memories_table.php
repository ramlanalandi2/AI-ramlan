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
        Schema::create('memories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contact_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');

            $table->string('memory_type')
                ->default('fact');

            $table->text('content');

            $table->integer('importance')
                ->default(3);

            $table->timestamp('last_used_at')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
