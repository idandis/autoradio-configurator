<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrderRefund extends Model
{
    protected $guarded = [];

    protected $casts = [
        'created_at_shopify' => 'datetime',
        'restock' => 'boolean',
    ];
}
