<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_statistics', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->nullable();
            $table->string('event_type')->index();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('variant_title')->nullable();
            $table->decimal('product_price', 10, 2)->nullable();
            $table->boolean('installation_selected')->default(false);
            $table->string('installation_type')->nullable();
            $table->boolean('camera_selected')->default(false);
            $table->string('postal_code', 10)->nullable();
            $table->string('service_zone')->nullable();
            $table->string('language', 10)->nullable();
            $table->text('referrer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->timestamps();

            $table->index(['session_uuid', 'event_type', 'created_at'], 'configuration_statistics_dedup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_statistics');
    }
};
