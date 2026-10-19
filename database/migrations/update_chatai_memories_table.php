<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a user asked the assistant to remember (preferences, facts about their work).
        Schema::create('chatai_memories', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user');
            $table->string('tenant_id')->nullable();
            $table->string('panel_id');
            $table->string('content', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatai_memories');
    }
};
