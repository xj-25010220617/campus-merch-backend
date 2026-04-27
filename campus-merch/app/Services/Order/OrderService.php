<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Common\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected AuditLogService $auditLogService,
    ) {}

    public function store(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            $product = Product::whereKey($data['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->inventoryService->reserve($product, $data['quantity']);

            $unitPrice = $product->price;
            $totalPrice = bcmul($unitPrice, $data['quantity'], 2);

            $fromStatus = OrderStatus::Draft->value;
            $toStatus = OrderStatus::Booked->value;

            $order = Order::create([
                'user_id'          => $user->id,
                'product_id'       => $product->id,
                'quantity'         => $data['quantity'],
                'unit_price'       => $unitPrice,
                'total_price'      => $totalPrice,
                'size'             => $data['size'] ?? null,
                'color'            => $data['color'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'remark'           => $data['remark'] ?? null,
                'status'           => $toStatus,
                'order_no'         => $this->generateOrderNo(),
            ]);

            $this->auditLogService->recordOrderTransition(
                operatorId: $user->id,
                orderId: $order->id,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                remark: "创建订单 {$order->order_no}，预扣库存 {$data['quantity']}",
            );

            return $order;
        });
    }

    public function complete(User $user, Order $order): Order
    {
        return DB::transaction(function () use ($user, $order) {
            $order = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'order' => '无权限操作',
                ]);
            }

            $fromStatus = $order->status;
            $toStatus = OrderStatus::Completed->value;

            OrderStateMachine::ensureTransition($fromStatus, $toStatus);

            $product = Product::whereKey($order->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->inventoryService->deduct($product, $order->quantity);

            $order->update([
                'status'       => $toStatus,
                'completed_at' => now(),
            ]);

            $this->auditLogService->recordOrderTransition(
                operatorId: $user->id,
                orderId: $order->id,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                remark: "完成订单 {$order->order_no}，扣减库存 {$order->quantity}",
            );

            return $order;
        });
    }

    public function myOrders(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = Order::with(['product', 'attachments'])
            ->where('user_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => $orders,
        ]);
    }

    private function generateOrderNo(): string
    {
        return 'ORD' . now()->format('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}