<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    // POST /api/orders
    public function store(StoreOrderRequest $request)
    {
        $order = $this->orderService->store(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'code' => 0,
            'message' => '下单成功',
            'data' => $order
        ]);
    }

    // POST /api/orders/{order}/complete
    public function complete(Request $request, Order $order)
    {
        $this->authorize('complete', $order);

        $order = $this->orderService->complete(
            $request->user(),
            $order
        );

        return response()->json([
            'code' => 0,
            'message' => '订单已完成',
            'data' => $order
        ]);
    }

    // GET /api/my-orders
    public function myOrders(Request $request)
    {
        return $this->orderService->myOrders($request);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $order->load(['product', 'attachments']);

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => $order,
        ]);
    }
}