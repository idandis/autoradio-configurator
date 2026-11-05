<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZoneProduct;
use App\Services\ProductHtml;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ImportedProductsController extends Controller
{
    public function exportShopify(Request $request, \App\Services\ImportedProductsExport $exporter)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        try {
            return response()->download($exporter->write($data['date']), 'prodotti-importati-'.$data['date'].'-shopify.xlsx');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['export_date' => $e->getMessage()]);
        }
    }

    public function destroy(ConfiguratorProduct $product): RedirectResponse
    {
        DB::transaction(function () use ($product): void {
            InstallationZoneProduct::query()
                ->where('product_handle', $product->handle)
                ->delete();

            $product->delete();
        });

        return back()->with('status', 'Prodotto eliminato.');
    }

    public function updatePrice(Request $request, ConfiguratorProduct $product): RedirectResponse
    {
        $validated = $request->validate(['price' => ['required', 'numeric', 'min:0', 'max:999999.99']]);
        $price = number_format((float) $validated['price'], 2, '.', '');
        $product->update(['price_min' => $price]);

        if ($product->variants()->count() === 1) {
            $product->variants()->first()->update(['price' => $price]);
        }

        return back()->with('status', 'Prezzo aggiornato.');
    }

    public function updateVariants(Request $request, ConfiguratorProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['required', 'integer', 'distinct', Rule::exists('configurator_variants', 'id')->where('configurator_product_id', $product->id)],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        DB::transaction(function () use ($product, $data): void {
            $product->newQuery()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            foreach ($data['variants'] as $variant) {
                $product->variants()->whereKey($variant['id'])->update([
                    'price' => number_format((float) $variant['price'], 2, '.', ''),
                ]);
            }
            $product->update(['price_min' => $product->variants()->min('price')]);
        });

        return back()->with('status', 'Prezzi delle varianti aggiornati.');
    }

    private function sourceUrl(?string $url): ?string
    {
        return $url && filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) ? $url : null;
    }

    private function productImages(ConfiguratorProduct $product): array
    {
        return collect([$product->image_url, ...($product->meta['gallery_images'] ?? []),
            ...$product->variants->pluck('image_url')->all(),
            ...array_column($product->meta['original_dashboard_images'] ?? [], 'url')])
            ->filter(fn ($url) => is_string($url) && ((str_starts_with($url, '/') && ! str_starts_with($url, '//'))
                || (filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true))))
            ->unique()->values()->all();
    }

    public function updateDashboard(Request $request, ConfiguratorProduct $product): RedirectResponse
    {
        $data = $request->validate(['image_url' => ['present', 'nullable', 'string', 'max:4096']]);
        DB::transaction(function () use ($product, $data) {
            $current = ConfiguratorProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $url = $data['image_url'];
            if ($url !== null && ! in_array($url, $this->productImages($current), true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['image_url' => 'Scegli una delle immagini di questo prodotto.']);
            }
            $meta = $current->meta ?? [];
            $meta['original_dashboard_images'] = $url === null ? [] : [['url' => $url, 'variant' => null]];
            $current->update(['meta' => $meta]);
        });

        return back()->with('status', 'Foto cruscotto aggiornata.');
    }

    public function updateVehicle(Request $request, ConfiguratorProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:255'], 'model' => ['required', 'string', 'max:2000'],
            'year_from' => ['required', 'integer', 'min:1', 'max:65535'],
            'year_to' => ['required', 'integer', 'gte:year_from', 'max:65535'],
        ]);
        $data['brand'] = mb_strtoupper(trim($data['brand']));
        $data['model'] = mb_strtoupper(trim($data['model']));
        $entries = app(\App\Services\VehicleImageResolver::class)->vehicleEntries($data['brand'], $data['model']);
        if (! $entries) throw \Illuminate\Validation\ValidationException::withMessages(['model' => 'Controlla le associazioni: ogni numero deve corrispondere a una marca.']);
        $meta = $product->meta ?? [];
        unset($meta['vehicle_compatibilities']);
        $product->update($data + ['meta' => $meta]);

        return back()->with('status', 'Compatibilità veicolo aggiornata.');
    }

    public function updateTitles(Request $request, ConfiguratorProduct $product): RedirectResponse
    {
        $validated = $request->validate([
            'title_it' => ['nullable', 'string', 'max:1000'],
            'title_en' => ['nullable', 'string', 'max:1000'],
        ]);

        $product->update([
            'title_it' => filled($validated['title_it'] ?? null) ? trim($validated['title_it']) : null,
            'title_en' => filled($validated['title_en'] ?? null) ? trim($validated['title_en']) : null,
        ]);

        return back()->with('status', 'Traduzioni del titolo aggiornate.');
    }

    public function updateDescription(Request $request, ConfiguratorProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'body_html_it' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'body_html_en' => ['sometimes', 'nullable', 'string', 'max:200000'],
        ]);
        foreach ($data as $key => $html) {
            $data[$key] = filled($html) ? ProductHtml::sanitize($html) : null;
        }
        $product->update($data);

        return back()->with('status', 'Descrizione aggiornata.');
    }

    public function __invoke(Request $request): Response
    {
        $category = $request->string('category')->toString();
        $search = trim($request->string('search')->toString());

        $products = ConfiguratorProduct::query()
            ->with('variants:id,configurator_product_id,title,sku,option_value,price,image_url')
            ->withCount('variants')
            ->whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])
            ->when(in_array($category, ['screen', 'camera', 'speaker', 'accessory'], true), function ($query) use ($category) {
                $query->where('category', $category);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('title_it', 'like', "%{$search}%")
                        ->orWhere('title_en', 'like', "%{$search}%")
                        ->orWhere('handle', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%");
                });
            })
            ->orderBy('category')
            ->orderBy('brand')
            ->orderBy('model')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ConfiguratorProduct $product) => [
                'id' => $product->id,
                'handle' => $product->handle,
                'title' => $product->title,
                'title_it' => $product->title_it,
                'title_en' => $product->title_en,
                'body_html' => ProductHtml::sanitize($product->body_html),
                'body_html_it' => ProductHtml::sanitize($product->body_html_it),
                'body_html_en' => ProductHtml::sanitize($product->body_html_en),
                'category' => $product->category,
                'subtype' => $product->subtype,
                'brand' => $product->brand,
                'model' => $product->model,
                'year_from' => $product->year_from,
                'year_to' => $product->year_to,
                'price_min' => $product->price_min,
                'variants_count' => $product->variants_count,
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'title' => $variant->title ?: $variant->option_value,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                ]),
                'source_url' => $this->sourceUrl($product->meta['autogenesi']['source_url'] ?? null),
                'image_url' => $product->image_url,
                'images' => $this->productImages($product),
                'dashboard_image_url' => $product->meta['original_dashboard_images'][0]['url'] ?? null,
            ]);

        return Inertia::render('ImportedProducts', [
            'importDates' => fn () => app(\App\Services\ImportedProductsExport::class)->dates(),
            'filters' => [
                'category' => $category,
                'search' => $search,
            ],
            'products' => $products,
        ]);
    }
}
