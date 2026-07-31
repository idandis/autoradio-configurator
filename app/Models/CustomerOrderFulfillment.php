<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrderFulfillment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'created_at_shopify' => 'datetime',
        'updated_at_shopify' => 'datetime',
    ];
}
