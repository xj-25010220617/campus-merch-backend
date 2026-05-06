<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_attachments', function (Blueprint $table) {
            $table->id()->comment('附件ID');
            $table->foreignId('order_id')->constrained()->cascadeOnDelete()->comment('订单ID');
            $table->string('type', 50)->default('design')->comment('附件类型: design/effect');
            $table->string('origin_name')->comment('原始文件名');
            $table->string('storage_path', 1024)->comment('存储路径');
            $table->string('mime_type', 255)->comment('MIME类型');
            $table->unsignedBigInteger('size')->comment('文件大小(字节)');
            $table->string('ext', 20)->comment('文件扩展名');
            $table->boolean('is_deleted')->default(false)->comment('是否已删除');
            $table->timestamps();

            $table->index(['order_id', 'type']);
            $table->comment('订单附件表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');
    }
};
