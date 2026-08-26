<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configurator_products', function (Blueprint $table) {
            $table->text('title_it')->nullable()->after('title');
            $table->text('title_en')->nullable()->after('title_it');
        });
    }

    public function down(): void
    {
        Schema::table('configurator_products', function (Blueprint $table) {
            $table->dropColumn(['title_it', 'title_en']);
        });
    }
};
