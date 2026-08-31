<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blocked_visitors') && ! Schema::hasTable('extra_eu_visitors')) {
            Schema::rename('blocked_visitors', 'extra_eu_visitors');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('extra_eu_visitors') && ! Schema::hasTable('blocked_visitors')) {
            Schema::rename('extra_eu_visitors', 'blocked_visitors');
        }
    }
};
