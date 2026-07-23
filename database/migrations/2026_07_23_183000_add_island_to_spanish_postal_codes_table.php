<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spanish_postal_codes', function (Blueprint $table) {
            $table->string('island')->nullable()->after('autonomous_community')->index();
        });
    }

    public function down(): void
    {
        Schema::table('spanish_postal_codes', function (Blueprint $table) {
            $table->dropColumn('island');
        });
    }
};
