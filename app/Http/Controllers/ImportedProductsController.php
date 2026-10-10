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
            ->with('variants:id,configurator_product_id,title,sku,option_value,price')
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
                'image_url' => $product->image_url,
            ]);

        return Inertia::render('ImportedProducts', [
            'filters' => [
                'category' => $category,
                'search' => $search,
            ],
            'products' => $products,
        ]);
    }
}
