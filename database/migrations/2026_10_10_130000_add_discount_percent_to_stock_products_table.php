<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_products') && ! Schema::hasColumn('stock_products', 'discount_percent')) {
            Schema::table('stock_products', fn (Blueprint $table) => $table->unsignedTinyInteger('discount_percent')->default(0));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_products', 'discount_percent')) {
            Schema::table('stock_products', fn (Blueprint $table) => $table->dropColumn('discount_percent'));
        }
    }
};
