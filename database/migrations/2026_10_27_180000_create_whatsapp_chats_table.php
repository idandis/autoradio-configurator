<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chats', function (Blueprint $table) {
            $table->id();
            $table->string('session');
            $table->string('phone', 20);
            $table->boolean('paused')->default(false);
            $table->string('pause_reason')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();
            $table->unique(['session', 'phone']);
        });
        Schema::create('whatsapp_chat_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_chat_id')->constrained()->cascadeOnDelete();
            $table->char('message_hash', 64);
            $table->string('kind', 10);
            $table->unique(['whatsapp_chat_id', 'message_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chat_receipts');
        Schema::dropIfExists('whatsapp_chats');
    }
};
