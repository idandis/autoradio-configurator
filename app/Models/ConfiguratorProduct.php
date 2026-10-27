<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'body_html',
        'body_html_it',
        'body_html_en',
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

    public function scopeUniversalScreens(Builder $query): Builder
    {
        return $query->where('category', 'screen')
            ->where(fn ($q) => $q->whereNull('meta->type')->orWhere('meta->type', '!=', 'DASHCAM'))
            ->whereNot(fn ($q) => $q->where('title', 'like', 'DVR %')->orWhere('title', 'like', 'Dashcam %')
                ->orWhere('title', 'like', 'Marco %')->orWhere('title', 'Donation'))
            ->whereRaw('LOWER(TRIM(model)) = ?', ['universal']);
    }

    public function stock(): HasMany
    {
        return $this->hasMany(StockProduct::class, 'product_handle', 'handle');
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

    public function localizedDescription(?string $locale = null): ?string
    {
        $translated = match ($locale ?? app()->getLocale()) {
            'it' => $this->body_html_it,
            'en' => $this->body_html_en,
            default => null,
        };

        return filled($translated) ? (string) $translated : $this->body_html;
    }
}
