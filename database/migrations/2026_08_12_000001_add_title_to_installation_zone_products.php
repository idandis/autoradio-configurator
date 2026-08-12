<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('installation_zone_products', 'title')) {
            return;
        }

        Schema::table('installation_zone_products', function (Blueprint $table) {
            $table->string('title')->nullable()->after('product_handle');
        });
    }

    public function down(): void
    {
        Schema::table('installation_zone_products', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
