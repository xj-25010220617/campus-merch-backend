<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product list']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product detail', 'id' => $id]);
    }

    public function update(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product update', 'id' => $id]);
    }

    public function import(): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product import']);
    }
}
