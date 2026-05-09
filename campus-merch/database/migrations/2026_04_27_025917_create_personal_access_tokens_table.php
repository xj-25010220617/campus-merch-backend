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
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id()->comment('令牌ID');
            $table->morphs('tokenable');
            $table->text('name')->comment('令牌名称');
            $table->string('token', 64)->unique()->comment('令牌值');
            $table->text('abilities')->nullable()->comment('权限范围');
            $table->timestamp('last_used_at')->nullable()->comment('最后使用时间');
            $table->timestamp('expires_at')->nullable()->index()->comment('过期时间');
            $table->timestamps();
            $table->comment('个人访问令牌表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
