<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('installation_zone_services')) {
            Schema::create('installation_zone_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installation_zone_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->decimal('price', 10, 2);
                $table->timestamps();
                $table->index(['installation_zone_id', 'name']);
            });
        }

        DB::table('installation_zone_products')
            ->leftJoin('configurator_products', 'configurator_products.handle', '=', 'installation_zone_products.product_handle')
            ->orderBy('installation_zone_products.id')
            ->select([
                'installation_zone_products.installation_zone_id',
                'installation_zone_products.title as custom_title',
                'installation_zone_products.price as custom_price',
                'configurator_products.title as imported_title',
                'configurator_products.price_min as imported_price',
            ])
            ->get()
            ->each(function ($product): void {
                $name = $product->custom_title ?: $product->imported_title;

                if (! $name) {
                    return;
                }

                DB::table('installation_zone_services')->updateOrInsert(
                    [
                        'installation_zone_id' => $product->installation_zone_id,
                        'name' => $name,
                    ],
                    [
                        'price' => $product->custom_price ?? $product->imported_price ?? 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            });

        DB::table('configurator_products')
            ->where('category', 'installation')
            ->orderBy('id')
            ->get(['title', 'price_min', 'meta'])
            ->each(function ($product): void {
                $meta = is_string($product->meta) ? json_decode($product->meta, true) : (array) $product->meta;
                $location = trim((string) ($meta['installation']['location'] ?? ''));
                $parts = preg_split('/\s+-\s+/u', trim($product->title), 2);
                $zoneName = $location ?: trim((string) ($parts[0] ?? ''));
                $serviceName = count($parts) === 2 ? trim($parts[1]) : trim($product->title);

                if ($zoneName === '' || $serviceName === '') {
                    return;
                }

                $zoneId = DB::table('installation_zones')->where('name', $zoneName)->value('id');
                if (! $zoneId) {
                    $zoneId = DB::table('installation_zones')->insertGetId([
                        'name' => $zoneName,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $alreadyExists = DB::table('installation_zone_services')
                    ->where('installation_zone_id', $zoneId)
                    ->where('name', $serviceName)
                    ->exists();

                if (! $alreadyExists) {
                    DB::table('installation_zone_services')->insert([
                        'installation_zone_id' => $zoneId,
                        'name' => $serviceName,
                        'price' => $product->price_min ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_zone_services');
    }
};
