<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrderLine extends Model
{
    protected $guarded = [];

    public function configuratorProduct(): BelongsTo
    {
        return $this->belongsTo(ConfiguratorProduct::class, 'product_handle', 'handle');
    }
}
