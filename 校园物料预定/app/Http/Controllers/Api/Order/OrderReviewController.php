<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderReviewController extends Controller
{
    public function review(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement order review', 'id' => $id]);
    }
}
