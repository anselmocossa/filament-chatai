<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A permission chosen for a tool (e.g. ViewAny:Order) instead of its own UseChatAiTool:X.
        Schema::table('chatai_tool_overrides', function (Blueprint $table): void {
            $table->string('permission')->nullable()->after('roles');
        });
    }

    public function down(): void
    {
        Schema::table('chatai_tool_overrides', fn (Blueprint $table) => $table->dropColumn('permission'));
    }
};
