<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedConfiguration extends Model
{
    protected $fillable = [
        'uuid',
        'fingerprint',
        'configuration',
        'checkout',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'checkout' => 'array',
        ];
    }
}
