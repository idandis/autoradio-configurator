<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configurator_products', function (Blueprint $table) {
            $table->dropIndex(['model']);
        });

        Schema::table('configurator_products', function (Blueprint $table) {
            $table->text('model')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('configurator_products', function (Blueprint $table) {
            $table->string('model')->nullable()->change();
            $table->index('model');
        });
    }
};
