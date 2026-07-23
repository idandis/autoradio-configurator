<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('installation_zone_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_zone_id')->constrained()->cascadeOnDelete();
            $table->string('postal_code_from', 5);
            $table->string('postal_code_to', 5);
            $table->timestamps();
            $table->index(['postal_code_from', 'postal_code_to']);
        });

        Schema::create('installation_zone_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_zone_id')->constrained()->cascadeOnDelete();
            $table->string('product_handle');
            $table->timestamps();
            $table->unique(['installation_zone_id', 'product_handle']);
            $table->index('product_handle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_zone_products');
        Schema::dropIfExists('installation_zone_postal_codes');
        Schema::dropIfExists('installation_zones');
    }
};
