<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguratorController extends Controller
{
    public function __invoke(): Response
    {
        $screenProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'screen')
            ->orderBy('brand')
            ->orderBy('model')
            ->get();

        $cameraProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'camera')
            ->orderBy('price_min')
            ->get()
            ->values();

        $installationProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'installation')
            ->get();

        $speakerProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'speaker')
            ->orderBy('title')
            ->get();

        return Inertia::render('Configurator', [
            'locale' => app()->getLocale(),
            'translations' => trans('configurator'),
            'vehicles' => $screenProducts->map(fn (ConfiguratorProduct $product) => [
                'id' => $product->id,
                'handle' => $product->handle,
                'title' => $product->title,
                'brand' => $product->brand,
                'model' => $product->model,
                'yearFrom' => $product->year_from,
                'yearTo' => $product->year_to,
                'image' => $product->image_url,
                'variants' => $product->variants
                    ->filter(fn ($variant) => filled($variant->option_value))
                    ->map(fn ($variant) => [
                        'id' => $variant->id,
                        'title' => $variant->option_value,
                        'sku' => $variant->sku,
                        'shopifyVariantId' => $variant->shopify_variant_id,
                        'price' => (float) $variant->price,
                        'image' => $variant->image_url ?: $product->image_url,
                    ])->sortBy('price')->values(),
            ])->values(),
            'cameraOptions' => $this->cameraOptions($cameraProducts),
            'speakerOptions' => $this->speakerOptions($speakerProducts),
            'installationOptions' => $this->installationOptions($installationProducts),
            'installationZones' => InstallationZone::query()
                ->where('active', true)
                ->with(['postalCodes', 'products'])
                ->orderBy('name')
                ->get()
                ->map(fn (InstallationZone $zone) => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'postalRanges' => $zone->postalCodes->map(fn ($range) => [
                        'from' => $range->postal_code_from,
                        'to' => $range->postal_code_to,
                    ])->values(),
                    'productHandles' => $zone->products->pluck('product_handle')->values(),
                ])->values(),
            'vehicleImages' => collect(glob(public_path('images/vehicles-dark/*.{png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort()
                ->values(),
            'brandImages' => collect(glob(public_path('images/brands/*.{png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort()
                ->values(),
        ]);
    }

    private function cameraOptions($products): array
    {
        $options = [];

        foreach ($products as $product) {
            $options[] = [
                'key' => $product->handle,
                'title' => $product->title,
                'price' => (float) $product->price_min,
                'image' => $product->image_url,
                'shopifyVariantId' => $product->variants->first()?->shopify_variant_id,
                'sku' => $product->variants->first()?->sku,
                'isStandard' => $product->handle === 'camara-trasera-estandar',
                'brand' => $product->brand,
                'model' => $product->model,
                'yearFrom' => $product->year_from,
                'yearTo' => $product->year_to,
            ];
        }

        return $options;
    }

    private function installationOptions($products): array
    {
        $options = [];

        foreach ($products->sortBy('price_min')->values() as $product) {
            $options[] = [
                'key' => $product->handle,
                'title' => $product->title,
                'price' => (float) $product->price_min,
                'shopifyVariantId' => $product->variants->first()?->shopify_variant_id,
                'sku' => $product->variants->first()?->sku,
                'subtype' => $product->subtype,
                'location' => $product->meta['installation']['location'] ?? null,
            ];
        }

        return $options;
    }

    private function speakerOptions($products): array
    {
        return $products
            ->groupBy(fn (ConfiguratorProduct $product) => mb_strtolower(trim($product->title)))
            ->map(function ($matchingProducts) {
                /** @var ConfiguratorProduct $product */
                $product = $matchingProducts->first();
                $variant = $matchingProducts
                    ->flatMap(fn (ConfiguratorProduct $matchingProduct) => $matchingProduct->variants)
                    ->filter(fn ($candidate) => filled($candidate->shopify_variant_id) && $candidate->price !== null)
                    ->sortBy('price')
                    ->first();

                if (! $variant) {
                    return null;
                }

                $sizes = $matchingProducts
                    ->flatMap(fn (ConfiguratorProduct $matchingProduct) => $matchingProduct->meta['speaker_sizes'] ?? [])
                    ->map(fn ($size) => trim((string) $size))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $categories = $matchingProducts
                    ->flatMap(fn (ConfiguratorProduct $matchingProduct) => $matchingProduct->meta['speaker_categories'] ?? [])
                    ->map(fn ($category) => trim((string) $category))
                    ->filter()
                    ->unique(fn ($category) => mb_strtolower($category))
                    ->values()
                    ->all();

                return [
                    'key' => 'speaker-'.$variant->id,
                    'handle' => $product->handle,
                    'title' => $product->title,
                    'productTitle' => $product->title,
                    'price' => (float) $variant->price,
                    'image' => $product->image_url ?: $variant->image_url,
                    'shopifyVariantId' => $variant->shopify_variant_id,
                    'sku' => $variant->sku,
                    'sizes' => $sizes,
                    'categories' => $categories,
                ];
            })
            ->filter()
            ->sortBy('price')
            ->values()
            ->all();
    }
}
