<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\StockProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class StockProductsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $ready = Schema::hasTable('stock_products');
        $stock = $ready ? StockProduct::with('product.variants')->orderBy('id')->get()->map(fn ($s) => [
            'id' => $s->id, 'handle' => $s->product_handle, 'quantity' => $s->quantity,
            'discountPercent' => $s->discount_percent ?? 0,
            'discountVariantId' => $s->discount_variant_key ? ($s->discountVariant()?->id ?? -1) : null,
            'variants' => $this->variants($s->product),
            'title' => $s->product?->localizedTitle('it') ?? $s->product_handle,
            'image' => $s->product?->image_url, 'available' => $s->product !== null,
        ]) : collect();
        $products = ConfiguratorProduct::query()->whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")->orWhere('title_it', 'like', "%{$search}%")
                ->orWhere('title_en', 'like', "%{$search}%")->orWhere('handle', 'like', "%{$search}%")
                ->orWhereHas('variants', fn ($q) => $q->where('sku', 'like', "%{$search}%"))))
            ->with('variants')->orderBy('title')->limit(20)->get()->map(fn ($p) => [
                'variants' => $this->variants($p), 'handle' => $p->handle, 'title' => $p->localizedTitle('it'), 'image' => $p->image_url,
            ]);

        return response()->json(compact('ready', 'stock', 'products'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_handle' => ['required', 'string', Rule::exists('configurator_products', 'handle')->whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:9999'],
            'discount_variant_id' => ['sometimes', 'nullable', 'integer'],
            'discount_percent' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
        ]);
        if (! Schema::hasTable('stock_products')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_10_10_120000_create_stock_products_table.php',
                '--force' => true,
                '--no-interaction' => true,
            ]);
            abort_unless(Schema::hasTable('stock_products'), 503, 'Gestione In stock non disponibile. Premi Aggiorna database dalla Dashboard e riprova.');
        }

        if (array_key_exists('discount_percent', $data)) $this->ensureDiscountColumn();
        $data = $this->variantData($data, $data['product_handle']);
        StockProduct::updateOrCreate(['product_handle' => $data['product_handle']], array_diff_key($data, ['product_handle' => true]));

        return response()->json(['saved' => true]);
    }

    public function update(Request $request, StockProduct $stockProduct): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['sometimes', 'required', 'integer', 'min:0', 'max:9999'],
            'discount_variant_id' => ['sometimes', 'nullable', 'integer'],
            'discount_percent' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
        ]);
        if (array_key_exists('discount_percent', $data)) $this->ensureDiscountColumn();
        if ($data === []) throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => 'Indica un campo da aggiornare.']);
        $data = $this->variantData($data, $stockProduct->product_handle);
        $stockProduct->update($data);

        return response()->json(['saved' => true]);
    }

    public function destroy(StockProduct $stockProduct): JsonResponse
    {
        $stockProduct->delete();

        return response()->json(['deleted' => true]);
    }

    private function variants(?ConfiguratorProduct $product): array
    {
        return $product?->variants->map(fn ($v) => ['id' => $v->id, 'title' => $v->option_value ?: $v->title, 'price' => $v->price])->all() ?? [];
    }

    private function variantData(array $data, string $handle): array
    {
        if (! array_key_exists('discount_variant_id', $data)) return $data;
        $variant = $data['discount_variant_id'] !== null
            ? ConfiguratorProduct::where('handle', $handle)->first()?->variants()->find($data['discount_variant_id']) : null;
        if ($data['discount_variant_id'] !== null && ! $variant) {
            throw \Illuminate\Validation\ValidationException::withMessages(['discount_variant_id' => 'Seleziona una variante di questo prodotto.']);
        }
        if (! Schema::hasColumn('stock_products', 'discount_variant_key')) {
            $migration = require database_path('migrations/2026_10_10_140000_add_discount_variant_key_to_stock_products_table.php');
            $migration->up();
        }
        $data['discount_variant_key'] = $variant ? StockProduct::variantKey($variant) : null;
        unset($data['discount_variant_id']);
        return $data;
    }

    private function ensureDiscountColumn(): void
    {
        if (Schema::hasColumn('stock_products', 'discount_percent')) return;

        try {
            $migration = require database_path('migrations/2026_10_10_130000_add_discount_percent_to_stock_products_table.php');
            $migration->up();
        } catch (\Throwable $e) {
            if (Schema::hasColumn('stock_products', 'discount_percent')) return;
            report($e);
            abort(503, 'Sconto non disponibile. Premi Aggiorna database dalla Dashboard e riprova.');
        }
    }
}
