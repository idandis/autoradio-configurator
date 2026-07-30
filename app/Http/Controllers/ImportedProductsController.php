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

    public function __invoke(Request $request): Response
    {
        $category = $request->string('category')->toString();
        $search = trim($request->string('search')->toString());

        $products = ConfiguratorProduct::query()
            ->withCount('variants')
            ->when(in_array($category, ['screen', 'camera', 'speaker', 'installation'], true), function ($query) use ($category) {
                $query->where('category', $category);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('title', 'like', "%{$search}%")
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
