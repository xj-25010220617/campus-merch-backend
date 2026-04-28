<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ReviewOrderRequest;
use App\Models\Order;
use App\Services\Order\OrderReviewService;

class OrderReviewController extends Controller
{
    public function __construct(
        protected OrderReviewService $reviewService
    ) {}

    // PUT /api/admin/orders/{id}/review
    public function review(ReviewOrderRequest $request, Order $order)
    {
        $order = $this->reviewService->review(
            $request->user(),
            $order,
            $request->validated()
        );

        return response()->json([
            'code' => 0,
            'message' => '审核完成',
            'data' => $order
        ]);
    }
}