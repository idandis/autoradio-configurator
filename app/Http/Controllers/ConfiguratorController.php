<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
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
            ->whereIn('handle', ['camara-trasera-estandar', 'camara-trasera-especifica'])
            ->orWhere(function ($query) {
                $query->where('category', 'camera')
                    ->where('subtype', 'ahd');
            })
            ->get()
            ->sortBy('price_min')
            ->values();

        $installationProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'installation')
            ->get();

        return Inertia::render('Configurator', [
            'vehicles' => $screenProducts->map(fn (ConfiguratorProduct $product) => [
                'id' => $product->id,
                'handle' => $product->handle,
                'title' => $product->title,
                'brand' => $product->brand,
                'model' => $product->model,
                'yearFrom' => $product->year_from,
                'yearTo' => $product->year_to,
                'image' => $product->image_url,
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'title' => $variant->title,
                    'sku' => $variant->sku,
                    'price' => (float) $variant->price,
                    'image' => $variant->image_url ?: $product->image_url,
                ])->sortBy('price')->values(),
            ])->values(),
            'cameraOptions' => $this->cameraOptions($cameraProducts),
            'installationOptions' => $this->installationOptions($installationProducts),
        ]);
    }

    private function cameraOptions($products): array
    {
        $options = [[
            'key' => 'none',
            'title' => 'Senza camera',
            'price' => 0,
            'image' => null,
        ]];

        $standard = $products->firstWhere('handle', 'camara-trasera-estandar');
        $specific = $products->firstWhere('handle', 'camara-trasera-especifica');
        $ahd = $products->firstWhere('subtype', 'ahd');

        foreach ([$standard, $specific, $ahd] as $product) {
            if (! $product) {
                continue;
            }

            $options[] = [
                'key' => $product->handle,
                'title' => $product->title,
                'price' => (float) $product->price_min,
                'image' => $product->image_url,
            ];
        }

        return $options;
    }

    private function installationOptions($products): array
    {
        $options = [[
            'key' => 'none',
            'title' => 'Senza installazione',
            'price' => 0,
        ]];

        $screenOnly = $products->where('subtype', 'screen_only')->sortBy('price_min')->first();
        $cameraOnly = $products->where('subtype', 'camera_only')->sortBy('price_min')->first();
        $screenCamera = $products->where('subtype', 'screen_camera')->sortBy('price_min')->first();

        foreach ([$screenOnly, $cameraOnly, $screenCamera] as $product) {
            if (! $product) {
                continue;
            }

            $options[] = [
                'key' => $product->handle,
                'title' => $product->title,
                'price' => (float) $product->price_min,
            ];
        }

        return $options;
    }
}
