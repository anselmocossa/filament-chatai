<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each account/endpoint with its own key: OpenRouter, OpenAI, a local Ollama, Anthropic…
        Schema::create('chatai_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('driver', 20); // openai (any OpenAI-compatible API) | anthropic
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable(); // encrypted; empty = the key from .env / settings
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('chatai_models', function (Blueprint $table): void {
            $table->foreignId('connection_id')->nullable()->after('id')->constrained('chatai_connections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chatai_models', fn (Blueprint $table) => $table->dropConstrainedForeignId('connection_id'));
        Schema::dropIfExists('chatai_connections');
    }
};
