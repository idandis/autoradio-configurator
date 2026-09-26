<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configurator_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('configurator_products', 'body_html_it')) {
                $table->longText('body_html_it')->nullable();
            }
            if (! Schema::hasColumn('configurator_products', 'body_html_en')) {
                $table->longText('body_html_en')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('configurator_products', function (Blueprint $table): void {
            $columns = array_values(array_filter(['body_html_it', 'body_html_en'], fn (string $column) => Schema::hasColumn('configurator_products', $column)));
            if ($columns !== []) $table->dropColumn($columns);
        });
    }
};
