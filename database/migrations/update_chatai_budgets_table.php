<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spending limits per provider, in the currency set on the settings page.
        Schema::table('chatai_connections', function (Blueprint $table): void {
            $table->decimal('monthly_limit', 12, 2)->nullable()->after('api_key');
            $table->decimal('daily_limit', 12, 2)->nullable()->after('monthly_limit');
        });

        // Which provider each call was billed to, to add up against the limits.
        Schema::table('chatai_usage', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_id')->nullable()->after('tenant_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('chatai_usage', fn (Blueprint $table) => $table->dropColumn('connection_id'));
        Schema::table('chatai_connections', fn (Blueprint $table) => $table->dropColumn(['monthly_limit', 'daily_limit']));
    }
};
