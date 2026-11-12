<?php

namespace App\Http\Controllers;

use App\Models\CatalogProductSheet;
use App\Models\ConfiguratorProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CatalogProductSheetController extends Controller
{
    public function export(ConfiguratorProduct $product, \App\Services\ImportedProductsExport $exporter)
    {
        try {
            return response()->download($exporter->writeProduct($product), 'prodotto-'.$product->id.'-shopify.xlsx');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }
    }

    public function show(ConfiguratorProduct $product)
    {
        $p = CatalogProductSheet::fromCatalog($product);
        $data = $p->toArray();
        $data['processing_epoch'] = app(\App\Services\ProductSheetProcessing::class)->epoch('catalog', $p->id);
        $entries = $p->metadata['vehicle_compatibilities'];
        if (!$entries && $p->brand && $p->model) $entries = array_map(fn ($e) => $e + ['year_from' => $p->year_from, 'year_to' => $p->year_to], app(\App\Services\VehicleImageResolver::class)->vehicleEntries($p->brand, $p->model));
        $data['vehicle_groups'] = app(\App\Services\AutogenesiVehicleTransform::class)->groups($entries);
        $data['images'] = array_map(fn ($im, $i) => [
            'url' => route('imported-products.sheet.image', [$product, $i, 'v' => $im['sha256']]), 'id' => $im['sha256'],
            'ai_edited' => (bool) ($im['ai_edited'] ?? false), 'image_reframed' => (bool) ($im['image_reframed'] ?? false), 'resolution_upscaled' => (bool) ($im['resolution_upscaled'] ?? false), 'manually_cropped' => (bool) (($im['manually_cropped'] ?? false) || ($im['manually_painted'] ?? false)),
            'dashboard_selected' => (bool) ($im['dashboard_selected'] ?? false), 'shopify_file_reference' => $im['shopify_file_reference'] ?? '',
            'ai_generated' => (bool) ($im['ai_generated'] ?? false), 'web_sources' => $im['web_sources'] ?? [], 'local_only' => false,
            'role' => $im['role'], 'source_url' => $im['source_url'] ?? null, 'width' => $im['width'], 'height' => $im['height'],
        ], $p->images, array_keys($p->images));
        return Inertia::render('Autogenesi', ['reimportImagesResult' => session('reimport_images_result'), 'catalogEdit' => true, 'catalogCategory' => $product->category, 'catalogRadio' => app(\App\Services\CatalogPriceUpdater::class)->isRadio($product), 'products' => [$data], 'local' => app()->environment('local'), 'batches' => [], 'detectionMaxYear' => now()->year - 1]);
    }

    public function action(Request $request, ConfiguratorProduct $product, ?int $index = null)
    {
        $p = CatalogProductSheet::fromCatalog($product);
        $action = substr($request->route()->getName(), strlen('imported-products.sheet.'));
        $methods = ['stop-processing' => 'stopProcessing', 'description-link' => 'descriptionFromLink', 'reimport-images' => 'reimportImages', 'update' => 'update', 'store-variant' => 'storeVariant', 'destroy-variant' => 'destroyVariant',
            'image' => 'image', 'remove-image' => 'removeImage', 'upload-images' => 'uploadImages', 'image-layout' => 'imageLayout',
            'edit-image' => 'editImage', 'white-image' => 'editImage', 'upscale-image' => 'editImage', 'clean-ai-image' => 'editImage', 'restore-image' => 'editImage',
            'paint-image' => 'paintImage', 'crop-image' => 'cropImage', 'cleanup-text' => 'cleanupText', 'clean-image' => 'cleanupText', 'dashboard-image' => 'generateDashboard'];
        abort_unless(isset($methods[$action]), 404);
        if ($index !== null && $action !== 'remove-image') {
            $id = $request->input('image_id', $request->query('v'));
            $im = $id ? collect($p->images)->firstWhere('sha256', $id) : ($p->images[$index] ?? null);
            abort_unless($im, 404);
            $this->materialize($im);
        }
        return app()->call([app(AutogenesiController::class), $methods[$action]], ['request' => $request, 'product' => $p, 'index' => $index]);
    }

    private function materialize(array $im): void
    {
        $disk = Storage::disk('local');
        if ($disk->exists($im['path'])) return;
        $url = $im['catalog_url'] ?? $im['source_url'] ?? '';
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $own = !parse_url($url, PHP_URL_HOST) || in_array(strtolower(parse_url($url, PHP_URL_HOST)), ['www.autoradioitaliano.it', 'autoradioitaliano.it', 'localhost', '127.0.0.1'], true);
        if ($own && str_starts_with($path, '/storage/') && !str_contains(rawurldecode($path), '..')) {
            $bytes = Storage::disk('public')->get(substr($path, 9));
        } else {
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            abort_unless(preg_match('/(?:^|\.)(?:shopify\.com|shopifycdn\.com|alicdn\.com|aliexpress-media\.com)$/', $host) && parse_url($url, PHP_URL_SCHEME) === 'https', 422, 'Host immagine non supportato: carica la foto dal computer.');
            $tmp = tmpfile();
            try {
                $response = Http::timeout(15)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'sink' => $tmp, 'progress' => function ($total, $downloaded) { if ($total > 16 * 1024 * 1024 || $downloaded > 16 * 1024 * 1024) throw new \RuntimeException('Foto troppo grande.'); }])->get($url);
                abort_unless($response->successful(), 422, 'Foto non raggiungibile.');
                rewind($tmp); $bytes = stream_get_contents($tmp, 16 * 1024 * 1024 + 1);
            } finally { fclose($tmp); }
        }
        $info = $bytes ? @getimagesizefromstring($bytes) : false;
        abort_unless($info && strlen($bytes) <= 16 * 1024 * 1024 && $info[0] * $info[1] <= 25000000, 422, 'Foto non valida.');
        $target = $im['path'];
        $im = @imagecreatefromstring($bytes);
        abort_unless($im, 422, 'Formato foto non supportato.');
        imagesavealpha($im, true); ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        abort_unless($disk->put($target, $png), 500, 'Salvataggio foto non riuscito.');
    }
}
