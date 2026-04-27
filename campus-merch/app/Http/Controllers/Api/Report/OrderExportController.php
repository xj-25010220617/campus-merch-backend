<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Services\Report\OrderExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderExportController extends Controller
{
    public function __construct(
        private readonly OrderExportService $exportService
    ) {}

    /**
     * GET /api/orders/export
     * 管理员按筛选条件导出订单 Excel 报表（流式下载）
     */
    public function export(Request $request): StreamedResponse
    {
        // 从 query 参数提取筛选条件
        $filters = array_filter([
            'status' => $request->query('status'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
            'product_id' => $request->query('product_id'),
            'keyword' => $request->query('keyword'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->exportService->export($filters);
    }
}
