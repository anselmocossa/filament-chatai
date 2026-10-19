<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatai_conversation_groups', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user');
            $table->string('tenant_id')->nullable();
            $table->string('panel_id');
            $table->string('name', 80);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('chatai_conversations', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable()->after('last_message_at');
            $table->unsignedBigInteger('group_id')->nullable()->index()->after('pinned_at');
        });
    }

    public function down(): void
    {
        Schema::table('chatai_conversations', function (Blueprint $table): void {
            $table->dropIndex(['group_id']);
            $table->dropColumn(['pinned_at', 'group_id']);
        });

        Schema::dropIfExists('chatai_conversation_groups');
    }
};
