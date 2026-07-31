<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'billing_address' => 'array',
        'shipping_address' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerOrderLine::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerOrderTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerOrderRefund::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(CustomerOrderFulfillment::class);
    }
}
