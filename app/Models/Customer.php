<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'shopify_id',
        'email',
        'first_name',
        'last_name',
        'phone',
        'language',
        'state',
        'note',
        'tags',
        'attention_color',
        'total_orders',
        'total_spent',
        'first_order_at',
        'last_order_at',
        'shopify_created_at',
        'shopify_updated_at',
    ];

    protected $casts = [
        'total_orders' => 'integer',
        'total_spent' => 'decimal:2',
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(CustomerCost::class);
    }

    public function supplierRefunds(): HasMany
    {
        return $this->hasMany(CustomerSupplierRefund::class);
    }

    public function latestOrder(): HasOne
    {
        return $this->hasOne(CustomerOrder::class)->ofMany('processed_at', 'max');
    }
}
