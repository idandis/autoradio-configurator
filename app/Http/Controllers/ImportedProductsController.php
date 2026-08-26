<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZoneProduct;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    public function __invoke(Request $request): Response
    {
        $category = $request->string('category')->toString();
        $search = trim($request->string('search')->toString());

        $products = ConfiguratorProduct::query()
            ->withCount('variants')
            ->whereIn('category', ['screen', 'camera', 'speaker'])
            ->when(in_array($category, ['screen', 'camera', 'speaker'], true), function ($query) use ($category) {
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
                'category' => $product->category,
                'subtype' => $product->subtype,
                'brand' => $product->brand,
                'model' => $product->model,
                'year_from' => $product->year_from,
                'year_to' => $product->year_to,
                'price_min' => $product->price_min,
                'variants_count' => $product->variants_count,
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
