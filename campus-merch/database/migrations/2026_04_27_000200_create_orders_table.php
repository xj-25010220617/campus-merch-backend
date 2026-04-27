<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id()->comment('订单ID');
            $table->string('order_no', 32)->unique()->comment('订单编号');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('下单用户ID');
            $table->foreignId('product_id')->constrained()->restrictOnDelete()->comment('商品ID');
            $table->unsignedInteger('quantity')->comment('购买数量');
            $table->decimal('unit_price', 10, 2)->comment('下单单价');
            $table->decimal('total_price', 10, 2)->comment('订单总价');
            $table->string('size', 50)->nullable()->comment('尺码');
            $table->string('color', 50)->nullable()->comment('颜色');
            $table->string('status', 30)->default('booked')->index()->comment('状态: draft/booked/design_pending/ready/completed/rejected');
            $table->string('shipping_address', 500)->nullable()->comment('收货地址');
            $table->text('remark')->nullable()->comment('用户备注');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->comment('审核人ID');
            $table->timestamp('reviewed_at')->nullable()->comment('审核时间');
            $table->text('review_reason')->nullable()->comment('审核/驳回原因');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->comment('订单表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
