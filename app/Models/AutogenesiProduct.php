<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutogenesiProduct extends Model
{
    public function locked(): self
    {
        return self::lockForUpdate()->findOrFail($this->id);
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'images' => 'array', 'metadata' => 'array'];
    }
}
