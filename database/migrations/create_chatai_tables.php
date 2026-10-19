<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatai_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->morphs('user');
            $table->string('tenant_id')->nullable();
            $table->string('panel_id');
            $table->string('title', 120)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_type', 'user_id', 'tenant_id', 'last_message_at']);
        });

        Schema::create('chatai_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('chatai_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content')->nullable();
            $table->json('components')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('tool_name')->nullable();
            $table->json('context')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 16)->default('complete');
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatai_messages');
        Schema::dropIfExists('chatai_conversations');
    }
};
