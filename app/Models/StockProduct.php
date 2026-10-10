<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class StockProduct extends Model
{
    protected $fillable = ['product_handle', 'quantity', 'discount_percent'];

    protected $casts = ['quantity' => 'integer', 'discount_percent' => 'integer'];

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
                'title' => $stock->product->localizedTitle('it'),
                'category' => $stock->product->category,
                'image' => $stock->product->image_url ?: $stock->product->variants->first(fn ($v) => filled($v->image_url))?->image_url,
                'price' => $stock->product->price_min !== null ? (float) $stock->product->price_min : null,
            ]);
    }
}
