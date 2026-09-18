<?php

use App\Support\ItalianOrderSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('italian_orders', 'checkout_token')) {
            Schema::table('italian_orders', fn (Blueprint $table) => $table->uuid('checkout_token')->nullable());
        }
        ItalianOrderSchema::index('italian_orders', ['checkout_token']);
        if (! Schema::hasColumn('italian_orders', 'is_test')) {
            Schema::table('italian_orders', fn (Blueprint $table) => $table->boolean('is_test')->default(false));
        }
    }

    public function down(): void
    {
        Schema::table('italian_orders', function (Blueprint $table) {
            $table->dropUnique(['checkout_token']);
            $table->dropColumn(['checkout_token', 'is_test']);
        });
    }
};
