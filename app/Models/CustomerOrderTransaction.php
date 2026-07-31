<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrderTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
        'is_test' => 'boolean',
    ];
}
