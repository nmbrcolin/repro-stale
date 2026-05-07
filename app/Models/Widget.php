<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    use HasUlids;

    protected $fillable = ['stale_since'];

    protected $casts = [
        'stale_since' => 'datetime',
    ];
}
