<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Overrides for config/chatai.php set from the settings page (values encrypted).
        Schema::create('chatai_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('chatai_preferences', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user');
            $table->string('voice')->nullable();
            $table->timestamps();
            $table->unique(['user_type', 'user_id']);
        });

        // One row per billable call: answered question, read-aloud or dictation.
        Schema::create('chatai_usage', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('user');
            $table->string('panel_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('conversation_id')->nullable();
            $table->string('kind', 20);
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('characters')->default(0);
            $table->decimal('seconds', 10, 2)->default(0);
            $table->decimal('cost', 14, 6)->nullable(); // null = no price set for that model
            $table->string('currency', 3)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatai_usage');
        Schema::dropIfExists('chatai_preferences');
        Schema::dropIfExists('chatai_settings');
    }
};
