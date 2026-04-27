<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement order creation']);
    }

    public function myOrders(): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement my orders']);
    }

    public function complete(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement order completion', 'id' => $id]);
    }
}
