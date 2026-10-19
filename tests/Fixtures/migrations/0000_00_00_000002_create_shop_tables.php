<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->integer('qty')->default(0);
            $table->integer('security_stock')->default(0);
            $table->timestamps();
        });

        Schema::create('shop_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('status');
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('shop_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_order_id');
            $table->foreignId('shop_product_id');
            $table->integer('qty');
            $table->decimal('unit_price', 10, 2);
        });
    }
};
