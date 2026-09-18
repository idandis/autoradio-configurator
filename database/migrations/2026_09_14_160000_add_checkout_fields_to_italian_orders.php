<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('italian_orders', function (Blueprint $table) {
            $table->uuid('checkout_token')->nullable()->unique();
            $table->boolean('is_test')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('italian_orders', function (Blueprint $table) {
            $table->dropUnique(['checkout_token']);
            $table->dropColumn(['checkout_token', 'is_test']);
        });
    }
};
