<?php

/**
 * ============================================================================
 * 【接口1控制器】商品管理控制器（含 Excel 批量导入）
 * ============================================================================
 *
 * 📌 功能概述：
 *    处理商品相关的 API 请求，包括：
 *    - 商品列表（index）—— ✅ 已实现
 *    - 商品详情（show）—— ✅ 已实现
 *    - 商品更新（update）—— ✅ 已实现（乐观锁）
 *    - **Excel 批量导入**（import）—— ✅ 已实现（本次任务的核心接口）
 *
 * 🔗 路由对应：
 *    GET  /api/products           → ProductController::index()
 *    GET  /api/products/{id}      → ProductController::show()
 *    PUT  /api/products/{id}      → ProductController::update()
 *    POST /api/products/import    → ProductController::import()
 */

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;                    // 基础控制器
use App\Http\Requests\Report\ImportProductsRequest;     // Excel导入的表单验证请求类
use App\Models\Product;                                 // 商品模型
use App\Services\Report\ProductImportService;           // Excel导入服务（业务逻辑层）
use Illuminate\Http\JsonResponse;                       // JSON 响应类
use Illuminate\Http\Request;                            // HTTP 请求类
use Illuminate\Support\Facades\Validator;               // 验证器

class ProductController extends Controller
{
    /**
     * 依赖注入 —— 注入商品导入服务
     */
    public function __construct(
        private readonly ProductImportService $importService // Excel 导入服务实例
    ) {}

    /**
     * GET /api/products — 商品列表
     *
     * 支持按分类筛选、价格区间筛选，并计算库存预警
     */
    public function index(Request $request): JsonResponse
    {
        // 1. 构建查询构造器
        $query = Product::query();

        // 2. 条件筛选
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('min_price') && $request->filled('max_price')) {
            $query->whereBetween('price', [$request->min_price, $request->max_price]);
        }

        // 3. 库存预警逻辑（可用库存 < 10 为预警）
        $products = $query->get();

        // 4. 数据转换：添加预警字段
        $data = $products->map(function ($product) {
            $availableStock = $product->stock - $product->reserved_stock;
            $product->is_stock_low = $availableStock < 10;

            $item = $product->toArray();
            $item['available_stock'] = $availableStock;
            return $item;
        });

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $data
        ]);
    }

    /**
     * GET /api/products/{id} — 商品详情
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::withCount('orders')->find($id);

        if (!$product) {
            return response()->json(['code' => 404, 'message' => '商品不存在']);
        }

        // 附件聚合（设计稿示例等）
        $attachments = [
            'design_examples' => [
                ['url' => '/example/design1.jpg', 'title' => '往期设计参考'],
                ['url' => '/example/design2.jpg', 'title' => '往期设计参考2'],
            ],
            'manual' => '/manual.pdf'
        ];

        $result = $product->toArray();
        $result['attachments'] = $attachments;

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $result
        ]);
    }

    /**
     * PUT /api/products/{id} — 更新商品（乐观锁）
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // 1. 验证数据
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric',
            'version' => 'required|integer' // 必须传入当前版本号
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()]);
        }

        $data = $request->only(['name', 'price', 'stock', 'custom_rule']);
        $currentVersion = $request->input('version');

        // 2. 乐观锁更新逻辑
        $updated = Product::where('id', $id)
            ->where('version', $currentVersion) // 核心：检查版本
            ->update([
                'name' => $data['name'],
                'price' => $data['price'],
                'stock' => $data['stock'] ?? 0,
                'custom_rule' => $data['custom_rule'] ?? null,
                'version' => $currentVersion + 1 // 版本+1
            ]);

        if ($updated == 0) {
            // 版本号不匹配（数据被其他人修改了）
            return response()->json([
                'code' => 409,
                'message' => '数据版本冲突，请刷新页面重试'
            ]);
        }

        return response()->json(['code' => 0, 'message' => '更新成功']);
    }

    /*
     * ──────────────────────────────────────────────
     * ★ 核心方法：import() Excel批量导入
     * ──────────────────────────────────────────────
     */

    /**
     * POST /api/products/import
     * 管理员上传 Excel 批量导入/更新商品（部分成功策略）
     *
     * 🔄 请求处理流程：
     *
     *    管理员浏览器/Postman
     *       ↓ POST /api/products/import (multipart/form-data)
     *       ↓ Headers: Authorization: Bearer {admin_token}
     *       ↓ Body: file=(Excel文件 .xlsx/.xls)
     *    ┌──────────────────┐
     *    │ ① ImportProducts-│ ← FormRequest 自动验证（是否上传文件、文件格式、大小等）
     *    │    Request         │    验证失败 → 返回 422 错误
     *    └────┬─────────────┘
     *       ↓ 验证通过
     *    ┌──────────────────┐
     *    │ ② 调用 Service    │ ← $importService->import($file)
     *    │    解析→校验→入库  │   （详见 ProductImportService）
     *    └────┬─────────────┘
     *       ↓ 得到结果
     *    ┌──────────────────┐
     *    │ ③ 判断结果类型    │ ← 根据成功/失败数量选择 HTTP 状态码
     *    │    选择状态码      │
     *    └────┬─────────────┘
     *       ↓
     *    ┌──────────────────┐
     *    │ ④ 返回 JSON 响应  │ ← 包含成功数、失败数、错误明细
     *    └──────────────────┘
     *
     * @param  ImportProductsRequest  $request  经过验证的表单请求对象
     * @return  JsonResponse                   JSON 格式的API响应（状态码动态决定）
     */
    public function import(ImportProductsRequest $request): JsonResponse
    {
        // ════════════════════════════════════════
        // 步骤1：获取上传的文件
        // ════════════════════════════════════════

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        // ════════════════════════════════════════
        // 步骤2：调用 Service 层执行导入逻辑
        // ════════════════════════════════════════

        $result = $this->importService->import($file);

        // ════════════════════════════════════════
        // 步骤3：根据结果选择 HTTP 状态码和消息
        // ════════════════════════════════════════

        if ($result['fail_count'] > 0 && $result['success_count'] === 0) {
            // 全部失败
            $status = 422;
            $message = "导入失败，共 {$result['fail_count']} 行数据有误。";

        } elseif ($result['fail_count'] > 0 && $result['success_count'] > 0) {
            // 部分成功
            $status = 200;
            $message = "部分导入成功。成功 {$result['success_count']} 条，失败 {$result['fail_count']} 条。";

        } else {
            // 全部成功
            $status = 201;
            $message = "导入完成，共成功导入 {$result['success_count']} 条商品。";
        }

        // ════════════════════════════════════════
        // 步骤4：返回统一的 JSON 响应格式
        // ════════════════════════════════════════

        return response()->json([
            'code'    => 0,
            'message' => $message,
            'data' => [
                'success_count' => $result['success_count'],
                'fail_count'    => $result['fail_count'],
                'errors'        => $result['errors'],
            ],
        ], $status);
    }
}
