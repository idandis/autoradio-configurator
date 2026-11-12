<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use App\Models\ConfiguratorProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AutogenesiCatalogImport
{
    public function importAll(): int
    {
        $created = [];
        $drafts = collect();
        try {
            $count = DB::transaction(function () use (&$created, &$drafts) {
                $drafts = AutogenesiProduct::where('status', 'draft')->orderBy('id')->lockForUpdate()->get();
                if ($drafts->isEmpty()) throw new RuntimeException('Nessuna bozza da importare.');
                foreach ($drafts as $draft) {
                    try {
                        $this->import($draft, $created);
                    } catch (\Throwable $e) {
                        throw new RuntimeException('Importazione annullata per «'.$draft->title.'»: '.$e->getMessage(), 0, $e);
                    }
                }
                foreach ($drafts as $draft) $draft->delete();

                return $drafts->count();
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($created);
            throw $e;
        }
        foreach ($drafts as $draft) {
            $paths = collect($draft->images)->flatMap(fn ($image) => [$image['path'], $image['uncropped_path'] ?? null,
                $image['original']['path'] ?? null, $image['original']['uncropped_path'] ?? null])->filter()->unique()->all();
            Storage::disk('local')->delete($paths);
            Storage::disk('local')->deleteDirectory('autogenesi/exports/'.$draft->id);
        }

        return $count;
    }

    public function import(AutogenesiProduct $draft, ?array &$created = null): ConfiguratorProduct
    {
        $local = Storage::disk('local');
        $public = Storage::disk('public');
        $written = [];
        try {
            $result = DB::transaction(function () use ($draft, $local, $public, &$written) {
                $source = AutogenesiProduct::lockForUpdate()->findOrFail($draft->id);
                Validator::make($source->toArray(), ['source_id' => 'required|regex:/^\d+$/', 'title' => 'required|string|max:255',
                    'body_html' => 'required|string', 'brand' => 'required|string', 'model' => 'required|string',
                    'year_from' => 'required|integer', 'year_to' => 'required|integer|gte:year_from', 'variants' => 'required|array|min:1',
                    'variants.*.sku' => 'required|string|distinct', 'variants.*.label' => 'required|string|max:255',
                    'variants.*.selling_price' => 'required|numeric|gt:0|max:999999', 'images' => 'required|array|min:1'])->validate();
                $product = ConfiguratorProduct::where('handle', $source->handle)->lockForUpdate()->first();
                if ($product && ($product->meta['autogenesi']['source_id'] ?? null) !== $source->source_id) {
                    throw new RuntimeException('Questo handle appartiene a un altro prodotto: importazione interrotta.');
                }
                $product ??= new ConfiguratorProduct;
                $images = $source->images;
                $gallery = [];
                $dashboard = [];
                $selectedDashboard = array_column(AutogenesiImageLayout::dashboards($source), 'sha256');
                foreach ($images as &$image) {
                    $path = $local->path($image['path']);
                    $info = is_file($path) && ! is_link($path) ? @getimagesize($path) : false;
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
                    if (! $ext || filesize($path) > 16 * 1024 * 1024 || $info[0] * $info[1] > 25000000) {
                        throw new RuntimeException('Una foto manca o non è valida. Nessun prodotto importato.');
                    }
                    $hash = hash_file('sha256', $path);
                    if ($hash !== $image['sha256']) {
                        throw new RuntimeException('Una foto è cambiata: riapri la bozza e riprova.');
                    }
                    $target = 'autogenesi/catalog/'.$source->source_id.'/'.$hash.'.'.$ext;
                    if (! $public->exists($target)) {
                        if (! $public->put($target, file_get_contents($path))) {
                            throw new RuntimeException('Copia foto nel configuratore non riuscita.');
                        }
                        $written[] = $target;
                    }
                    $image['configurator_path'] = $target;
                    $image['configurator_sha256'] = $hash;
                    $url = '/storage/'.$target;
                    $gallery[] = $url;
                    if (in_array($image['sha256'], $selectedDashboard, true)) {
                        $dashboard[] = ['url' => $url, 'variant' => $image['variant'] ?? null, 'shopify_file_reference' => $image['shopify_file_reference'] ?? null];
                    }
                }
                unset($image);
                $row = app(AutogenesiTemplateExport::class)->rows($source)[0];
                $vehicleFields = app(AutogenesiVehicleTransform::class)->fromFields($source->brand, $source->model);
                $meta = array_replace($product->meta ?? [], ['imported_at' => now()->toIso8601String(), 'type' => 'Radio AM/FM', 'din' => $row[AutogenesiTemplateExport::DIN],
                    'vehicle_compatibilities' => $source->metadata['vehicle_compatibilities'] ?? [],
                    'gallery_images' => array_values(array_unique($gallery)), 'original_dashboard_images' => $dashboard,
                    'autogenesi' => ['source_id' => $source->source_id, 'source_url' => $source->source_url, 'additional_source_urls' => $source->metadata['additional_source_urls'] ?? [], 'draft_id' => $source->id, 'imported_at' => now()->toIso8601String()]]);
                if ($product->exists && $product->title !== $source->title) {
                    $product->fill(['title_it' => null, 'title_en' => null]);
                }
                if ($product->exists && $product->body_html !== $source->body_html) {
                    $product->fill(['body_html_it' => null, 'body_html_en' => null]);
                }
                $product->fill(['handle' => $source->handle, 'category' => 'screen', 'subtype' => null, 'title' => $source->title,
                    'body_html' => $source->body_html, 'brand' => $vehicleFields['brand'], 'model' => $vehicleFields['model'],
                    'year_from' => $source->year_from, 'year_to' => $source->year_to, 'option_name' => 'Versión',
                    'price_min' => min(array_column($source->variants, 'selling_price')), 'image_url' => $gallery[0], 'meta' => $meta])->save();
                foreach ($source->variants as $variant) {
                    $record = $product->variants()->firstOrNew(['sku' => $variant['sku']]);
                    $values = ['title' => $variant['label'], 'option_value' => $variant['label'], 'price' => $variant['selling_price'],
                        'image_url' => $gallery[0], 'meta' => array_replace($record->meta ?? [], ['variant_cost' => $variant['supplier_price'],
                            'source_sku_id' => $variant['source_sku_id'] ?? '', 'source_product_id' => $variant['source_product_id'] ?? $source->source_id,
                            'source_options' => $variant['source_options'] ?? [], 'regional_prices' => $variant['regional_prices'] ?? []])];
                    if (! empty($variant['shopify_variant_id'])) {
                        $values['shopify_variant_id'] = $variant['shopify_variant_id'];
                    }
                    $record->fill($values)->save();
                }
                $product->variants()->whereNotIn('sku', array_column($source->variants, 'sku'))->delete();
                $metadata = $source->metadata;
                $metadata['configurator_import'] = ['product_id' => $product->id, 'imported_at' => now()->toIso8601String(), 'images' => count($gallery), 'variants' => count($source->variants)];
                $source->update(['metadata' => $metadata, 'images' => $images]);

                return $product->fresh('variants');
            });
            if ($created !== null) array_push($created, ...$written);

            return $result;
        } catch (\Throwable $e) {
            $public->delete($written);
            throw $e;
        }
    }
}
