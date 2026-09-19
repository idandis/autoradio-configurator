<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shared_configurations', 'fingerprint')) {
            Schema::table('shared_configurations', function (Blueprint $table) {
                $table->string('fingerprint', 64)->nullable()->unique()->after('uuid');
            });
        }
        if (! Schema::hasColumn('shared_configurations', 'checkout')) {
            Schema::table('shared_configurations', function (Blueprint $table) {
                $table->json('checkout')->nullable()->after('configuration');
            });
        }
    }

    public function down(): void
    {
        Schema::table('shared_configurations', function (Blueprint $table) {
            $table->dropUnique(['fingerprint']);
            $table->dropColumn(['fingerprint', 'checkout']);
        });
    }
};
