<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'category',
        'type',
        'spec',
        'price',
        'stock',
        'reserved_stock',
        'sold_stock',
        'cover_url',
        'custom_rule',
        'status',
        'version',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
