<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationZoneService extends Model
{
    protected $fillable = ['name', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(InstallationZone::class, 'installation_zone_id');
    }
}
