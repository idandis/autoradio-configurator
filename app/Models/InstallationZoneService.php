<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationZoneService extends Model
{
    protected $fillable = ['name', 'name_it', 'name_en', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(InstallationZone::class, 'installation_zone_id');
    }

    public function localizedName(?string $locale = null): string
    {
        $translated = match ($locale ?? app()->getLocale()) {
            'it' => $this->name_it,
            'en' => $this->name_en,
            default => null,
        };

        return filled($translated) ? (string) $translated : (string) $this->name;
    }
}
