<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files attached to a question, and documents the assistant generated for download.
        Schema::create('chatai_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('chatai_conversations')->cascadeOnDelete();
            $table->string('message_id')->nullable()->index();
            $table->morphs('user');
            $table->string('kind', 12); // upload | generated
            $table->string('name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('disk', 40);
            $table->string('path');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatai_files');
    }
};
