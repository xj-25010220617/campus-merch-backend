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
    // 1. 修正可用库存计算逻辑 (适配 reserved_stock 字段名)
    public function getAvailableStockAttribute()
    {
        return $this->stock - $this->sold_stock - $this->reserved_stock;
    }

    // 2. 检查库存方法
    public function hasSufficientStock($quantity)
    {
        return $this->available_stock >= $quantity;
    }

    // 3. 乐观锁更新方法 (配合 version 字段)
    // 这个方法用于商品编辑保存时，确保数据未被他人修改
    public function updateWithLock(array $data, $currentVersion)
    {
        return $this->where('id', $this->id)
            ->where('version', $currentVersion) // 检查版本是否匹配
            ->update(array_merge($data, ['version' => $currentVersion + 1]));
    }


    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
