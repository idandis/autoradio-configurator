<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationZonePostalCode extends Model
{
    protected $fillable = ['postal_code_from', 'postal_code_to'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(InstallationZone::class, 'installation_zone_id');
    }
}
