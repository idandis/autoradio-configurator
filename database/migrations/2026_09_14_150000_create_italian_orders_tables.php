<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('italian_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('customer_name');
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->json('shipping_address');
            $table->json('billing_address')->nullable();
            $table->char('currency', 3)->default('EUR');
            // Monetary values are stored in cents; totals are purchase snapshots.
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->string('payment_status')->default('pending')->index();
            $table->string('fulfillment_status')->default('pending')->index();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('italian_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('italian_order_id')->constrained()->cascadeOnDelete();
            // Deliberately no catalog foreign key: reimports must not delete order lines.
            $table->string('product_handle');
            $table->string('sku')->nullable();
            $table->text('title');
            $table->string('variant_title')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('total_amount');
            $table->timestamps();
        });

        Schema::create('italian_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('italian_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->json('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('italian_order_events');
        Schema::dropIfExists('italian_order_items');
        Schema::dropIfExists('italian_orders');
    }
};
