<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    protected $fillable = [
        'shopify_id',
        'first_name',
        'last_name',
        'company',
        'phone',
        'line_1',
        'line_2',
        'city',
        'province',
        'province_code',
        'country',
        'country_code',
        'zip',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
