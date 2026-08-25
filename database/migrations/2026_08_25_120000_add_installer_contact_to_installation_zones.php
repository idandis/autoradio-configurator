<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_zones', function (Blueprint $table) {
            $table->string('installer_address', 500)->nullable()->after('name');
            $table->string('installer_phone', 50)->nullable()->after('installer_address');
        });
    }

    public function down(): void
    {
        Schema::table('installation_zones', function (Blueprint $table) {
            $table->dropColumn(['installer_address', 'installer_phone']);
        });
    }
};
