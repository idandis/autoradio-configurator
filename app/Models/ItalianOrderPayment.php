<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItalianOrderPayment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_payload', 'idempotency_key'];

    protected $casts = ['request_payload' => 'array', 'expires_at' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ItalianOrder::class, 'italian_order_id');
    }
}
