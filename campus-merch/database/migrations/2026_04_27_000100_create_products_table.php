<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id()->comment('商品ID');
            $table->string('name')->comment('商品名称');
            $table->string('category', 100)->index()->comment('分类');
            $table->string('type', 100)->index()->comment('类型');
            $table->string('spec', 255)->nullable()->comment('规格');
            $table->decimal('price', 10, 2)->comment('单价');
            $table->unsignedInteger('stock')->default(0)->comment('可用库存');
            $table->unsignedInteger('reserved_stock')->default(0)->comment('预扣库存');
            $table->unsignedInteger('sold_stock')->default(0)->comment('已售库存');
            $table->string('cover_url', 2048)->nullable()->comment('封面图URL');
            $table->text('custom_rule')->nullable()->comment('定制规则');
            $table->string('status', 20)->default('active')->index()->comment('状态: draft/active/inactive/archived');
            $table->unsignedInteger('version')->default(0)->comment('版本号(乐观锁)');
            $table->timestamps();
            $table->softDeletes();
            $table->comment('商品表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
