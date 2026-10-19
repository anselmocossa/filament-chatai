<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thumbs up / down on an answer, with an optional comment, for whoever looks after the assistant.
        Schema::table('chatai_messages', function (Blueprint $table): void {
            $table->smallInteger('feedback')->nullable();
            $table->text('feedback_comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatai_messages', function (Blueprint $table): void {
            $table->dropColumn(['feedback', 'feedback_comment']);
        });
    }
};
