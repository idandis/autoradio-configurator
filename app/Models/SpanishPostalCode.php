<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpanishPostalCode extends Model
{
    protected $fillable = [
        'postal_code',
        'place_name',
        'province',
        'autonomous_community',
        'island',
        'localities',
        'latitude',
        'longitude',
        'accuracy',
        'source',
    ];

    protected $casts = [
        'localities' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy' => 'integer',
    ];
}
