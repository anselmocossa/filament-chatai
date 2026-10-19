<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The models the assistant may use, in order: the first is the main one, the rest are fallbacks.
        Schema::create('chatai_models', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('model');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->decimal('input_price', 12, 6)->nullable(); // per million tokens
            $table->decimal('output_price', 12, 6)->nullable();
            $table->decimal('cache_read_price', 12, 6)->nullable();
            $table->decimal('cache_write_price', 12, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatai_models');
    }
};
