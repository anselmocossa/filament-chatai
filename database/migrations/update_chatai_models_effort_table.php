<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reasoning effort per model (any provider); null = the model's own default.
        Schema::table('chatai_models', function (Blueprint $table): void {
            $table->string('effort', 10)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('chatai_models', fn (Blueprint $table) => $table->dropColumn('effort'));
    }
};
