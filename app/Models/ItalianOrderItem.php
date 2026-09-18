<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItalianOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_amount' => 'integer',
        'total_amount' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ItalianOrder::class, 'italian_order_id');
    }
}
