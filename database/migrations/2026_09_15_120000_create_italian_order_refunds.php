<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('italian_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('italian_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->string('payment_intent');
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('creating');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('italian_order_refunds');
    }
};
