<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ImportProductsRequest;
use App\Services\Report\ProductImportService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductImportService $importService
    ) {}

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

    /**
     * POST /api/products/import
     * 管理员上传 Excel 批量导入/更新商品（部分成功策略）
     */
    public function import(ImportProductsRequest $request): JsonResponse
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        $result = $this->importService->import($file);

        // 根据结果选择 HTTP 状态码和消息
        if ($result['fail_count'] > 0 && $result['success_count'] === 0) {
            $status = 422; // 全部失败：Unprocessable Entity
            $message = "导入失败，共 {$result['fail_count']} 行数据有误。";
        } elseif ($result['fail_count'] > 0 && $result['success_count'] > 0) {
            $status = 200; // 部分成功：OK
            $message = "部分导入成功。成功 {$result['success_count']} 条，失败 {$result['fail_count']} 条。";
        } else {
            $status = 201; // 全部成功：Created
            $message = "导入完成，共成功导入 {$result['success_count']} 条商品。";
        }

        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => [
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count'],
                'errors' => $result['errors'],
            ],
        ], $status);
    }
}
