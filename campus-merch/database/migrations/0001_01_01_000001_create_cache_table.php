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
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('缓存键');
            $table->mediumText('value')->comment('缓存值');
            $table->bigInteger('expiration')->index()->comment('过期时间戳');
            $table->comment('缓存表');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('锁键');
            $table->string('owner')->comment('锁持有者');
            $table->bigInteger('expiration')->index()->comment('过期时间戳');
            $table->comment('缓存锁表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
