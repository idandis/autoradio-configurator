<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('italian_orders', 'import_amount')) {
            Schema::table('italian_orders', fn (Blueprint $table) => $table->unsignedBigInteger('import_amount')->default(0)->after('subtotal_amount'));
        }
        if (! Schema::hasColumn('italian_orders', 'checkout_locale')) {
            Schema::table('italian_orders', fn (Blueprint $table) => $table->string('checkout_locale', 2)->default('it')->after('is_test'));
        }
        if (! Schema::hasColumn('italian_orders', 'checkout_origin')) {
            Schema::table('italian_orders', fn (Blueprint $table) => $table->string('checkout_origin')->nullable()->after('checkout_locale'));
        }
        if (! Schema::hasColumn('italian_order_items', 'import_unit_amount')) {
            Schema::table('italian_order_items', fn (Blueprint $table) => $table->unsignedBigInteger('import_unit_amount')->default(0)->after('unit_amount'));
        }
        if (! Schema::hasColumn('italian_order_items', 'import_total_amount')) {
            Schema::table('italian_order_items', fn (Blueprint $table) => $table->unsignedBigInteger('import_total_amount')->default(0)->after('total_amount'));
        }
    }

    public function down(): void
    {
        Schema::table('italian_order_items', fn (Blueprint $table) => $table->dropColumn(['import_unit_amount', 'import_total_amount']));
        Schema::table('italian_orders', fn (Blueprint $table) => $table->dropColumn(['import_amount', 'checkout_locale', 'checkout_origin']));
    }
};
