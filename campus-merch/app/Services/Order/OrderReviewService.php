<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Common\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderReviewService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected AuditLogService $auditLogService,
    ) {}

    public function review(User $admin, Order $order, array $data): Order
    {
        return DB::transaction(function () use ($admin, $order, $data) {
            $order = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromStatus = $order->status;
            $toStatus = $data['action'] === 'approve'
                ? OrderStatus::Ready
                : OrderStatus::Rejected;

            OrderStateMachine::ensureTransition($fromStatus->value, $toStatus->value);

            if ($data['action'] === 'approve') {
                $order->update([
                    'status'      => $toStatus,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);
            } else {
                $product = Product::whereKey($order->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->inventoryService->release($product, $order->quantity);

                $order->update([
                    'status'        => $toStatus,
                    'review_reason' => $data['reason'],
                    'reviewed_by'   => $admin->id,
                    'reviewed_at'   => now(),
                ]);
            }

            $label = OrderStateMachine::getTransitionLabel($fromStatus->value, $toStatus->value);
            $this->auditLogService->recordOrderTransition(
                operatorId: $admin->id,
                orderId: $order->id,
                fromStatus: $fromStatus->value,
                toStatus: $toStatus->value,
                remark: "{$label} - 订单 {$order->order_no}",
            );

            return $order;
        });
    }
}