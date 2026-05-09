<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            // 1. 关联订单表
            $table->foreignId('order_id')->constrained()->onDelete('cascade');

            // 2. 关联商品表 (这就是报错缺失的字段)
            $table->foreignId('product_id')->constrained()->onDelete('cascade');

            // 3. 购买数量和价格快照
            $table->integer('quantity');
            $table->decimal('price', 10, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
