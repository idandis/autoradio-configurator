<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigurationStatistic extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'product_price' => 'decimal:2',
            'installation_selected' => 'boolean',
            'camera_selected' => 'boolean',
        ];
    }
}
