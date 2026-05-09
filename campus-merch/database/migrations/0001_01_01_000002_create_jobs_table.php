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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id()->comment('任务ID');
            $table->string('queue')->index()->comment('队列名称');
            $table->longText('payload')->comment('任务载荷');
            $table->unsignedSmallInteger('attempts')->comment('重试次数');
            $table->unsignedInteger('reserved_at')->nullable()->comment('预留时间');
            $table->unsignedInteger('available_at')->comment('可用时间');
            $table->unsignedInteger('created_at')->comment('创建时间');
            $table->comment('队列表');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary()->comment('批次ID');
            $table->string('name')->comment('批次名称');
            $table->integer('total_jobs')->comment('总任务数');
            $table->integer('pending_jobs')->comment('待处理任务数');
            $table->integer('failed_jobs')->comment('失败任务数');
            $table->longText('failed_job_ids')->comment('失败任务ID列表');
            $table->mediumText('options')->nullable()->comment('批次选项');
            $table->integer('cancelled_at')->nullable()->comment('取消时间');
            $table->integer('created_at')->comment('创建时间');
            $table->integer('finished_at')->nullable()->comment('完成时间');
            $table->comment('任务批次表');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id()->comment('失败任务ID');
            $table->string('uuid')->unique()->comment('唯一标识');
            $table->text('connection')->comment('连接名');
            $table->text('queue')->comment('队列名');
            $table->longText('payload')->comment('任务载荷');
            $table->longText('exception')->comment('异常信息');
            $table->timestamp('failed_at')->useCurrent()->comment('失败时间');
            $table->comment('失败任务表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
