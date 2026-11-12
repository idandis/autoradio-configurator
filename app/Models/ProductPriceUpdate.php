<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPriceUpdate extends Model
{
    protected $guarded = [];
    protected $casts = ['items' => 'array', 'user_id' => 'integer', 'generation' => 'integer'];
}
