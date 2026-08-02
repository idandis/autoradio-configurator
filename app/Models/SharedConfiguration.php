<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedConfiguration extends Model
{
    protected $fillable = [
        'uuid',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
        ];
    }
}
