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
    public function createFromAliExpress(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['source_url' => ['required', 'url', 'max:2048']]);
        try {
            set_time_limit(900);
            $url = app(\App\Services\AutogenesiSearch::class)->sourceUrl($data['source_url']);
            if (!$url) throw new \RuntimeException('Inserisci il link completo di un prodotto AliExpress.');
            $id = \App\Services\AutogenesiSource::id($url);
            $canonicalUrl = 'https://es.aliexpress.com/item/'.$id.'.html';
            $product = ConfiguratorProduct::where('meta->autogenesi->source_url', $canonicalUrl)
                ->orWhere('meta->autogenesi->source_url', $url)
                ->orWhere('meta->source_url', $canonicalUrl)
                ->orWhere('meta->source_url', $url)
                ->orWhere('meta->autogenesi->source_id', $id)
                ->first();
            if (!$product) {
                $draft = app(\App\Services\AutogenesiApiImport::class)->importUrl($request->user()->id, $url);
                $product = app(\App\Services\AutogenesiCatalogImport::class)->import($draft);
                $paths = collect($draft->images)->flatMap(fn ($image) => [$image['path'] ?? null, $image['uncropped_path'] ?? null,
                    $image['original']['path'] ?? null, $image['original']['uncropped_path'] ?? null])->filter()->unique()->all();
                $draft->delete();
                \Illuminate\Support\Facades\Storage::disk('local')->delete($paths);
            }
            return redirect()->route('imported-products.sheet', $product);
        } catch (\Throwable $e) {
            report($e);
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                \Illuminate\Support\Facades\Log::warning('AutoGenesi: dati non validi durante importazione', [
                    'fields' => array_keys($e->errors()), 'file' => $e->getFile(), 'line' => $e->getLine(),
                ]);
                return back()->withErrors(['source_url' => 'Dati del prodotto incompleti ('.implode(', ', array_keys($e->errors())).'). Riprova o usa l’importazione in AutoGenesi.']);
            }
            if (!$e instanceof \RuntimeException) {
                \Illuminate\Support\Facades\Log::warning('AutoGenesi: errore importazione non classificato', [
                    'type' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(),
                ]);
            }
            return back()->withErrors(['source_url' => $e instanceof \RuntimeException ? $e->getMessage() : 'Importazione non completata. Controlla il log locale e riprova.']);
        }
    }

    public function variantMatcher(Request $request, ConfiguratorProduct $product): Response
    {
        abort_unless(app()->environment('local'), 403);

        return Inertia::render('ImportedProductVariantMatcher', [
            'product' => ['id' => $product->id, 'title' => $product->title],
            'allowReplacement' => $request->boolean('replace'),
        ]);
    }

    public function recoverDsers(Request $request, \App\Services\DsersLinkRecovery $recovery): RedirectResponse
    {
        abort_unless(app()->environment('local'), 403);
        $request->validate(['dsers_file' => ['required', 'file', 'max:5120']]);
        try {
            $data = json_decode(file_get_contents($request->file('dsers_file')->getRealPath()), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['dsers_file' => 'File JSON non valido.']);
        }
        $data = validator(is_array($data) ? $data : [], [
            'format' => 'required|in:autogenesi.dsers.v1', 'rows' => 'required|array|min:1|max:5000',
            'rows.*.shopify_product_id' => 'nullable|string|regex:/^\d+$/',
            'rows.*.title_es' => 'nullable|string|max:1000',
            'rows.*.supplier_url' => 'required|string|max:2048',
        ])->validate();
        $result = $recovery->recover($data['rows']);
        return back()->with('status', "Link DSers: {$result['updated']} prodotti aggiornati; {$result['existing']} già con link; {$result['unmatched']} righe non abbinate; {$result['ambiguous']} ambigue.");
    }

    public function exportShopify(Request $request, \App\Services\ImportedProductsExport $exporter)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        try {
            return response()->download($exporter->write($data['date']), 'prodotti-importati-'.$data['date'].'-shopify.xlsx');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['export_date' => $e->getMessage()]);
        }
    }

    public function exportAllRadios(\App\Services\ImportedProductsExport $exporter)
    {
        try {
            return response()->download($exporter->writeAllRadios(), 'tutte-le-radio-shopify.xlsx');
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

    private function sourceUrl(ConfiguratorProduct $product): ?string
    {
        $urls = [$product->meta['autogenesi']['source_url'] ?? null, $product->meta['source_url'] ?? null];
        if (preg_match('/-ae-([0-9]+)$/', $product->handle, $match)) $urls[] = 'https://es.aliexpress.com/item/'.$match[1].'.html';
        foreach ($urls as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
                && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)) return $url;
        }
        return null;
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
        $data = $request->validate(['image_urls' => 'sometimes|array|max:200', 'image_urls.*' => 'required|string|max:4096|distinct', 'image_url' => 'sometimes|nullable|string|max:4096']);
        abort_unless(array_key_exists('image_urls', $data) || array_key_exists('image_url', $data), 422);
        DB::transaction(function () use ($product, $data) {
            $current = ConfiguratorProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $urls = $data['image_urls'] ?? (isset($data['image_url']) ? [$data['image_url']] : []);
            if (array_diff($urls, $this->productImages($current))) {
                throw \Illuminate\Validation\ValidationException::withMessages([array_key_exists('image_urls', $data) ? 'image_urls' : 'image_url' => 'Scegli solo immagini di questo prodotto.']);
            }
            $meta = $current->meta ?? [];
            $old = collect($meta['original_dashboard_images'] ?? [])->keyBy('url');
            $meta['original_dashboard_images'] = array_map(fn ($url) => $old->get($url) ?? ['url' => $url, 'variant' => null], $urls);
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'title_it' => ['nullable', 'string', 'max:1000'],
            'title_en' => ['nullable', 'string', 'max:1000'],
        ]);

        $product->update([
            ...(array_key_exists('title', $validated) ? ['title' => trim($validated['title'])] : []),
            'title_it' => filled($validated['title_it'] ?? null) ? trim($validated['title_it']) : null,
            'title_en' => filled($validated['title_en'] ?? null) ? trim($validated['title_en']) : null,
        ]);

        return back()->with('status', 'Titoli aggiornati.');
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
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? '';

        $products = ConfiguratorProduct::query()
            ->with('variants:id,configurator_product_id,title,sku,option_value,price,image_url,meta')
            ->withCount('variants')
            ->whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])
            ->when($date !== '', fn ($query) => $query->whereIn('id', app(\App\Services\ImportedProductsExport::class)->idsOnDate($date)))
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
                'variants' => $product->variants->sortBy(fn ($variant) => app(\App\Services\CatalogPriceUpdater::class)->variantOrder($variant))->values()->map(fn ($variant) => [
                    'id' => $variant->id,
                    'title' => $variant->title ?: $variant->option_value,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'supplier_cost' => $variant->meta['variant_cost'] ?? null,
                ]),
                'source_url' => $this->sourceUrl($product),
                'image_url' => $product->image_url,
                'images' => $this->productImages($product),
                'dashboard_image_url' => $product->meta['original_dashboard_images'][0]['url'] ?? null,
                'dashboard_image_urls' => array_column($product->meta['original_dashboard_images'] ?? [], 'url'),
            ]);

        return Inertia::render('ImportedProducts', [
            'canRecoverDsers' => app()->environment('local'),
            'priceUpdate' => fn () => app(CatalogPriceUpdateController::class)->snapshot($request->user()->id),
            'dsersStatus' => $request->session()->get('status'),
            'importDates' => fn () => app(\App\Services\ImportedProductsExport::class)->dates(),
            'filters' => [
                'category' => $category,
                'search' => $search,
                'date' => $date,
            ],
            'products' => $products,
        ]);
    }
}
