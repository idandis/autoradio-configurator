<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configurator_variants', function (Blueprint $table) {
            $table->string('shopify_variant_id')->nullable()->index()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('configurator_variants', function (Blueprint $table) {
            $table->dropColumn('shopify_variant_id');
        });
    }
};
