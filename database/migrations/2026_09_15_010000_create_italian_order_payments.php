<?php

use App\Support\ItalianOrderSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ItalianOrderSchema::create('italian_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('italian_order_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('status')->default('creating');
            $table->json('request_payload');
            $table->unsignedBigInteger('expires_at');
            $table->timestamps();
        });
        if (! Schema::hasColumn('italian_order_events', 'kind')) {
            Schema::table('italian_order_events', fn (Blueprint $table) => $table->string('kind')->default('fulfillment'));
        }
    }

    public function down(): void
    {
        Schema::table('italian_order_events', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::dropIfExists('italian_order_payments');
    }
};
