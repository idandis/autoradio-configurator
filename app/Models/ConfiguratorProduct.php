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
        'title_it',
        'title_en',
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

    public function localizedTitle(?string $locale = null): string
    {
        $translated = match ($locale ?? app()->getLocale()) {
            'it' => $this->title_it,
            'en' => $this->title_en,
            default => null,
        };

        return filled($translated) ? (string) $translated : (string) $this->title;
    }
}
