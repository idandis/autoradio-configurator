<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_statistics', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('device_type')->index();
            $table->string('region')->nullable()->after('country_code')->index();
            $table->string('city')->nullable()->after('region')->index();
        });
    }

    public function down(): void
    {
        Schema::table('configuration_statistics', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
            $table->dropIndex(['region']);
            $table->dropIndex(['city']);
            $table->dropColumn(['country_code', 'region', 'city']);
        });
    }
};
