<?php

namespace App\Models;

// ✅ 必须引入这些核心类
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;


    /*
     * Class Product
     *
     * @property int $id
     * @property string $name
     * @property string $orders  <-- 注意：这是你的 JSON 字段
     * @property \Illuminate\Support\Carbon $created_at
     * @property \Illuminate\Support\Carbon $updated_at
     *
     * @mixin \Eloquent  <-- 这行注释是关键，它告诉 IDE：我有所有 Eloquent 的魔法方法（包括 withCount）
     */
class Product extends Model
{
    // ✅ 必须引入 SoftDeletes Trait
    use SoftDeletes;

    // ✅ 必须定义表名（如果遵循复数规则可省略，但建议显式定义）
    protected $table = 'products';

    // ✅ 允许批量赋值的字段
    protected $fillable = [
        'name', 'category', 'type', 'spec', 'price',
        'cover_image', 'images', 'orders', 'status', 'sort'
    ];

    // ✅ 将 JSON 字段转换为数组
    protected $casts = [
        'images' => 'array',
        'orders' => 'array', // 注意：这里是数据库字段名
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联订单详情
     * 注意：我们将方法名改为 orderItems，以避免和字段名 'orders' 冲突
     *
     * @return HasMany
     */
    public function orderItems()
    {
        // 假设关联的模型是 App\Models\OrderItem
        // 假设外键是 product_id
        return $this->hasMany(\App\Models\OrderItem::class, 'product_id', 'id');
    }
}
