<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin adjustments to tools that live in code (MCP or array tools).
        Schema::create('chatai_tool_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('tool_name')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('roles')->nullable();
            $table->timestamps();
        });

        // Tools made on screen: query a panel resource, or call an allowed GET API.
        Schema::create('chatai_custom_tools', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('type', 20);
            $table->text('config'); // encrypted: may hold an API key
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('chatai_skills', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
            $table->text('when_to_use');
            $table->longText('instructions');
            $table->json('tools')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('chatai_usage', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->after('conversation_id');
            $table->unsignedInteger('latency_ms')->nullable()->after('seconds');
            $table->json('tools')->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('chatai_usage', fn (Blueprint $table) => $table->dropColumn(['message_id', 'latency_ms', 'tools']));
        Schema::dropIfExists('chatai_skills');
        Schema::dropIfExists('chatai_custom_tools');
        Schema::dropIfExists('chatai_tool_overrides');
    }
};
