<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InstallationZone extends Model
{
    protected $fillable = ['name', 'installer_address', 'installer_phone', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function matchesLocation(string $text): bool
    {
        $normalize = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($s))));
        $name = $normalize($this->name);
        $names = [$name];
        if ($name === 'gran canaria') $names[] = 'las palmas';
        $text = ' '.$normalize($text).' ';

        return collect($names)->contains(fn ($n) => $n !== '' && str_contains($text, ' '.$n.' '));
    }

    public function postalCodes(): HasMany
    {
        return $this->hasMany(InstallationZonePostalCode::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(InstallationZoneProduct::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(InstallationZoneService::class);
    }
}
