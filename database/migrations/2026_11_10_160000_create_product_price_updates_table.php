<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_price_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->unsignedInteger('generation')->default(1);
            $table->json('items');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('product_price_updates'); }
};
