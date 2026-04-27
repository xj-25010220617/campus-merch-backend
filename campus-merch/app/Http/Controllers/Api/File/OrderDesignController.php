<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderDesignController extends Controller
{
    public function store(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement design upload', 'id' => $id]);
    }
}
