<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_statistics', function (Blueprint $table) {
            $table->string('visit_key', 64)->nullable()->unique()->after('session_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('configuration_statistics', function (Blueprint $table) {
            $table->dropUnique(['visit_key']);
            $table->dropColumn('visit_key');
        });
    }
};
