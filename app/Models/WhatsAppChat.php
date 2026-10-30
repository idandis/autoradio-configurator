<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppChat extends Model
{
    use SoftDeletes;

    protected $table = 'whatsapp_chats';

    protected $fillable = ['session', 'phone', 'paused', 'pause_reason', 'last_activity_at', 'paused_at', 'resumed_at', 'import_label_id', 'import_label_name'];

    protected $casts = ['paused' => 'boolean', 'revision' => 'integer', 'last_activity_at' => 'datetime',
        'paused_at' => 'datetime', 'resumed_at' => 'datetime'];
}
