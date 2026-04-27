<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50)->default('design');
            $table->string('origin_name');
            $table->string('storage_path', 1024);
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('size');
            $table->string('ext', 20);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');
    }
};
