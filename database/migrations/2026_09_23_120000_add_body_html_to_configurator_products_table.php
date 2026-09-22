<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configurator_products', function (Blueprint $table) {
            $table->longText('body_html')->nullable()->after('title_en');
        });
    }

    public function down(): void
    {
        Schema::table('configurator_products', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
