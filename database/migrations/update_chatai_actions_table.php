<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Changes the assistant proposed; they only run once the user confirms.
        Schema::create('chatai_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('chatai_conversations')->cascadeOnDelete();
            $table->morphs('user');
            $table->string('tool');
            $table->string('title')->nullable();
            $table->json('arguments')->nullable();
            $table->json('context')->nullable();
            $table->string('status', 16)->default('pending');
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatai_actions');
    }
};
