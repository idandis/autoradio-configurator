<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallationZone extends Model
{
    protected $fillable = ['name', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function postalCodes(): HasMany
    {
        return $this->hasMany(InstallationZonePostalCode::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(InstallationZoneProduct::class);
    }
}
