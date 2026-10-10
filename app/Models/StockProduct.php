<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class StockProduct extends Model
{
    protected $fillable = ['product_handle', 'quantity', 'discount_percent', 'discount_variant_key'];

    protected $casts = ['quantity' => 'integer', 'discount_percent' => 'integer'];

    public static function variantKey(ConfiguratorVariant $variant): string
    {
        return filled($variant->shopify_variant_id) ? 'shopify:'.$variant->shopify_variant_id
            : (filled($variant->sku) ? 'sku:'.$variant->sku : 'title:'.hash('sha256', $variant->option_value ?: $variant->title));
    }

    public function discountVariant(): ?ConfiguratorVariant
    {
        return $this->discount_variant_key ? $this->product?->variants->first(fn ($v) => static::variantKey($v) === $this->discount_variant_key) : null;
    }

    public function appliesTo(?string $variantKey): bool
    {
        return ! $this->discount_variant_key || $this->discount_variant_key === $variantKey;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ConfiguratorProduct::class, 'product_handle', 'handle');
    }

    public static function publicProducts(): Collection
    {
        if (! Schema::hasTable('stock_products')) {
            return collect();
        }

        return static::query()->with('product.variants')->where('quantity', '>', 0)
            ->whereHas('product', fn ($q) => $q->whereIn('category', ['screen', 'camera', 'speaker', 'accessory']))
            ->orderBy('id')->get()->map(fn ($stock) => [
                'id' => $stock->product->id,
                'handle' => $stock->product_handle,
                'discountPercent' => $stock->discount_percent ?? 0,
                'discountVariantId' => $stock->discount_variant_key ? ($stock->discountVariant()?->id ?? -1) : null,
                'variantTitle' => $stock->discountVariant()?->option_value ?: $stock->discountVariant()?->title,
                'title' => $stock->product->localizedTitle('it'),
                'category' => $stock->product->category,
                'image' => $stock->product->image_url ?: $stock->product->variants->first(fn ($v) => filled($v->image_url))?->image_url,
                'price' => $stock->discount_variant_key ? ($stock->discountVariant()?->price !== null ? (float) $stock->discountVariant()->price : null)
                    : ($stock->product->price_min !== null ? (float) $stock->product->price_min : null),
            ]);
    }
}
