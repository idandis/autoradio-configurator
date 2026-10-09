<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\StockProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StockProductsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $ready = Schema::hasTable('stock_products');
        $stock = $ready ? StockProduct::with('product')->orderBy('id')->get()->map(fn ($s) => [
            'id' => $s->id, 'handle' => $s->product_handle, 'quantity' => $s->quantity,
            'title' => $s->product?->localizedTitle('it') ?? $s->product_handle,
            'image' => $s->product?->image_url, 'available' => $s->product !== null,
        ]) : collect();
        $products = ConfiguratorProduct::query()->whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")->orWhere('title_it', 'like', "%{$search}%")
                ->orWhere('title_en', 'like', "%{$search}%")->orWhere('handle', 'like', "%{$search}%")
                ->orWhereHas('variants', fn ($q) => $q->where('sku', 'like', "%{$search}%"))))
            ->orderBy('title')->limit(20)->get()->map(fn ($p) => [
                'handle' => $p->handle, 'title' => $p->localizedTitle('it'), 'image' => $p->image_url,
            ]);

        return response()->json(compact('ready', 'stock', 'products'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_handle' => ['required', 'string', Rule::exists('configurator_products', 'handle')->whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);
        StockProduct::updateOrCreate(['product_handle' => $data['product_handle']], ['quantity' => $data['quantity']]);

        return response()->json(['saved' => true]);
    }

    public function update(Request $request, StockProduct $stockProduct): JsonResponse
    {
        $stockProduct->update($request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:9999']]));

        return response()->json(['saved' => true]);
    }

    public function destroy(StockProduct $stockProduct): JsonResponse
    {
        $stockProduct->delete();

        return response()->json(['deleted' => true]);
    }
}
