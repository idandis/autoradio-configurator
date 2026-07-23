<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spanish_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('postal_code', 5)->unique();
            $table->string('place_name')->nullable();
            $table->string('province')->nullable()->index();
            $table->string('autonomous_community')->nullable()->index();
            $table->json('localities')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedTinyInteger('accuracy')->nullable();
            $table->string('source')->default('GeoNames');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spanish_postal_codes');
    }
};
