<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_id')->unique();
            $table->string('name')->index();
            $table->string('number')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_line_items', 12, 2)->default(0);
            $table->decimal('total_discount', 12, 2)->default(0);
            $table->decimal('total_shipping', 12, 2)->default(0);
            $table->decimal('total_refund', 12, 2)->default(0);
            $table->decimal('total_outstanding', 12, 2)->default(0);
            $table->decimal('current_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment_status')->nullable()->index();
            $table->string('fulfillment_status')->nullable()->index();
            $table->text('additional_details')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable();
            $table->string('product_id')->nullable();
            $table->string('product_handle')->nullable()->index();
            $table->string('variant_id')->nullable();
            $table->string('title');
            $table->text('name')->nullable();
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('vendor')->nullable();
            $table->text('properties')->nullable();
            $table->string('fulfillment_service')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_order_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable();
            $table->string('kind')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->string('gateway')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('wallet')->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('error_code')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable();
            $table->timestamp('created_at_shopify')->nullable();
            $table->text('note')->nullable();
            $table->boolean('restock')->default(false);
            $table->string('restock_type')->nullable();
            $table->string('restock_location')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('created_at_shopify')->nullable();
            $table->timestamp('updated_at_shopify')->nullable();
            $table->string('tracking_company')->nullable();
            $table->string('location')->nullable();
            $table->string('shipment_status')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('tracking_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_fulfillments');
        Schema::dropIfExists('customer_order_refunds');
        Schema::dropIfExists('customer_order_transactions');
        Schema::dropIfExists('customer_order_lines');
        Schema::dropIfExists('customer_orders');
    }
};
