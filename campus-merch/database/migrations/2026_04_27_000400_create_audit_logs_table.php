<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id()->comment('日志ID');
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete()->comment('操作人ID');
            $table->string('target_type', 100)->comment('目标类型: order/product/user');
            $table->unsignedBigInteger('target_id')->comment('目标ID');
            $table->string('action', 100)->comment('操作动作: order_transition/product_update等');
            $table->json('before_data')->nullable()->comment('变更前数据快照');
            $table->json('after_data')->nullable()->comment('变更后数据快照');
            $table->text('remark')->nullable()->comment('备注说明');
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['operator_id', 'action']);
            $table->comment('审计日志表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
