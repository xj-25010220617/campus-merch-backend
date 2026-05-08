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
        'status'        => OrderStatus::class,
        'unit_price'    => 'decimal:2',
        'total_price'   => 'decimal:2',
        'quantity'      => 'integer',
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
        return $this->hasMany(OrderAttachment::class)
            ->where('is_deleted', false);
    }

    public function isBelongsTo(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            OrderStatus::DRAFT          => '草稿',
            OrderStatus::BOOKED         => '已预订',
            OrderStatus::DESIGN_PENDING => '待设计',
            OrderStatus::READY          => '待领取',
            OrderStatus::COMPLETED      => '已完成',
            OrderStatus::REJECTED       => '已驳回',
            default                     => '未知状态',
        };
    }
}