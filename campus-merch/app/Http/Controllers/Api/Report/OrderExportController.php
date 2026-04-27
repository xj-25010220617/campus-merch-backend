<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderExportController extends Controller
{
    public function export(): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement order export']);
    }
}
