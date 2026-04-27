<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->string('action', 100);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['operator_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
