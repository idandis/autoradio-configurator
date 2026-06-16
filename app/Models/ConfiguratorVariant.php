<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguratorVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'configurator_product_id',
        'title',
        'sku',
        'shopify_variant_id',
        'option_value',
        'price',
        'image_url',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ConfiguratorProduct::class, 'configurator_product_id');
    }
}
