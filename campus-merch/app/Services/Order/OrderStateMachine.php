<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use Illuminate\Validation\ValidationException;

class OrderStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['booked'],
        'booked' => ['design_pending', 'rejected'],
        'design_pending' => ['ready', 'rejected'],
        'ready' => ['completed'],
        'rejected' => ['booked'],
    ];

    private const TRANSITION_LABELS = [
        'draft->booked' => '提交订单',
        'booked->design_pending' => '上传设计稿',
        'booked->rejected' => '下单被驳回',
        'design_pending->ready' => '审核通过',
        'design_pending->rejected' => '审核驳回',
        'ready->completed' => '确认收货',
        'rejected->booked' => '重新下单',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function ensureTransition(string $from, string $to): void
    {
        if (!static::canTransition($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "订单状态不允许从 [{$from}] 变更为 [{$to}]",
            ]);
        }
    }

    public static function getTransitionLabel(string $from, string $to): string
    {
        return self::TRANSITION_LABELS["{$from}->{$to}"] ?? '';
    }

    public static function getAllowedNextStatuses(string $current): array
    {
        return self::TRANSITIONS[$current] ?? [];
    }

    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
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