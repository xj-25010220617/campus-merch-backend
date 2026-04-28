<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_no',
        'user_id',
        'product_id',
        'quantity',
        'unit_price',
        'total_price',
        'size',
        'color',
        'remark',
        'status',
        'shipping_address',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'completed_at',
    ];

    protected $casts = [
        'unit_price'    => 'decimal:2',
        'total_price'   => 'decimal:2',
        'reviewed_at'   => 'datetime',
        'completed_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->order_no)) {
                $order->order_no = 'ORD' . now()->format('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderAttachment::class);
    }

    public function isBelongsTo(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            OrderStatus::Draft->value          => '草稿',
            OrderStatus::Booked->value         => '已预订',
            OrderStatus::DesignPending->value  => '待审核',
            OrderStatus::Ready->value          => '已就绪',
            OrderStatus::Completed->value      => '已完成',
            OrderStatus::Rejected->value       => '已驳回',
            default                            => '未知',
        };
    }
}