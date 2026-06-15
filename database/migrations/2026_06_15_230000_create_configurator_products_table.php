<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configurator_products', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('category')->index();
            $table->string('subtype')->nullable()->index();
            $table->string('title');
            $table->string('brand')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->unsignedSmallInteger('year_from')->nullable()->index();
            $table->unsignedSmallInteger('year_to')->nullable()->index();
            $table->string('option_name')->nullable();
            $table->decimal('price_min', 10, 2)->nullable();
            $table->text('image_url')->nullable();
            $table->text('tags')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('configurator_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configurator_product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('option_value')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->text('image_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurator_variants');
        Schema::dropIfExists('configurator_products');
    }
};
