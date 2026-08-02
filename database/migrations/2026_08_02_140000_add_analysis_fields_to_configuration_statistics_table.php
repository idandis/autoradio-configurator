<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_statistics', function (Blueprint $table) {
            $table->string('product_type')->nullable()->after('variant_id')->index();
            $table->decimal('configuration_value', 10, 2)->nullable()->after('product_price');
        });
    }

    public function down(): void
    {
        Schema::table('configuration_statistics', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropColumn(['product_type', 'configuration_value']);
        });
    }
};
