<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const HANDLE = 'adaptador-carplay-y-android-auto-inalambrico-para-citroen-c4-cactus-2014-2018-con-sistema-smeg-mrn';

    public function up(): void
    {
        DB::table('configurator_products')
            ->where('handle', self::HANDLE)
            ->where('brand', 'CITROEN')
            ->update(['brand' => 'PEUGEOT | CITROEN']);
    }

    public function down(): void
    {
        DB::table('configurator_products')
            ->where('handle', self::HANDLE)
            ->where('brand', 'PEUGEOT | CITROEN')
            ->update(['brand' => 'CITROEN']);
    }
};
