<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    // 允许批量赋值的字段
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        // 根据你的实际业务添加其他字段
    ];

    // 如果需要关联回 Product 或 Order，也可以在这里定义
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
