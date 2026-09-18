<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItalianOrderRefund extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'payment_intent'];

    protected $casts = ['amount' => 'integer'];
}
