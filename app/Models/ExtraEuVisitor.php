<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtraEuVisitor extends Model
{
    protected $fillable = [
        'fingerprint', 'country_code', 'region', 'city', 'device_type',
        'browser_language', 'referrer', 'requested_path', 'user_agent',
        'hits', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
