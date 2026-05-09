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
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('用户ID');
            $table->string('name')->comment('用户名');
            $table->string('email')->unique()->comment('邮箱');
            $table->string('phone', 20)->nullable()->comment('手机号');
            $table->string('role', 20)->default('user')->comment('角色: user/admin');
            $table->string('status', 20)->default('active')->comment('状态: active/disabled');
            $table->timestamp('email_verified_at')->nullable()->comment('邮箱验证时间');
            $table->string('password')->comment('密码');
            $table->rememberToken()->comment('记住我令牌');
            $table->timestamps();
            $table->comment('用户表');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('邮箱');
            $table->string('token')->comment('重置令牌');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->comment('密码重置令牌表');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary()->comment('会话ID');
            $table->foreignId('user_id')->nullable()->index()->comment('用户ID');
            $table->string('ip_address', 45)->nullable()->comment('IP地址');
            $table->text('user_agent')->nullable()->comment('用户代理');
            $table->longText('payload')->comment('会话数据');
            $table->integer('last_activity')->index()->comment('最后活动时间');
            $table->comment('会话表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
