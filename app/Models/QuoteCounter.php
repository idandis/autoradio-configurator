<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteCounter extends Model
{
    protected $fillable = [
        'quote_date',
        'last_number',
    ];
}
