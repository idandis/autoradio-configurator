<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_costs', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->after('amount');
            $table->decimal('exchange_rate', 12, 6)->default(1)->after('currency');
            $table->decimal('amount_eur', 12, 2)->default(0)->after('exchange_rate');
        });

        DB::table('customer_costs')->update([
            'currency' => 'EUR',
            'exchange_rate' => 1,
            'amount_eur' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('customer_costs', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'amount_eur']);
        });
    }
};
