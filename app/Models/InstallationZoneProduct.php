<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationZoneProduct extends Model
{
    protected $fillable = ['product_handle'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(InstallationZone::class, 'installation_zone_id');
    }
}
