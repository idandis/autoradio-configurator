<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfiguratorProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'handle',
        'category',
        'subtype',
        'title',
        'brand',
        'model',
        'year_from',
        'year_to',
        'option_name',
        'price_min',
        'image_url',
        'tags',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'price_min' => 'decimal:2',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ConfiguratorVariant::class);
    }
}
