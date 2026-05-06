<?php

/**
 * ============================================================================
 * 【接口1控制器】商品管理控制器（含 Excel 批量导入）
 * ============================================================================
 *
 * 📌 功能概述：
 *    处理商品相关的 API 请求，包括：
 *    - 商品列表（index）—— TODO 待实现
 *    - 商品详情（show）—— TODO 待实现
 *    - 商品更新（update）—— TODO 待实现
 *    - **Excel 批量导入**（import）—— ✅ 已实现（本次任务的核心接口）
 *
 * 🔗 路由对应：
 *    POST /api/products/import → ProductController::import()
 *
 * 💡 import() 方法的核心职责：
 *    1. 接收用户上传的 Excel 文件（FormRequest 已完成基础验证）
 *    2. 调用 ProductImportService 执行解析+校验+入库逻辑
     *3. 根据导入结果选择合适的 HTTP 状态码返回响应
     *
 * 📖 关于 HTTP 状态码的选择策略：
     *    - 201 Created：全部成功（创建了新资源）
     *    - 200 OK：部分成功（有成功也有失败，请求本身被正确处理了）
     *    - 422 Unprocessable Entity：全部失败（请求数据有问题，无法处理）
     *    这种"根据结果动态选状态码"的做法是 RESTful API 的最佳实践之一。
 */

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;                    // 基础控制器
use App\Http\Requests\Report\ImportProductsRequest;     // Excel导入的表单验证请求类
use App\Services\Report\ProductImportService;           // Excel导入服务（业务逻辑层）
use Illuminate\Http\JsonResponse;                       // JSON 响应类

class ProductController extends Controller
{
    /**
     * 依赖注入 —— 注入商品导入服务
     */
    public function __construct(
        private readonly ProductImportService $importService // Excel 导入服务实例
    ) {}

    /*
     * ──────────────────────────────────────────────
     * TODO 方法（非本次任务范围，预留占位）
     * ──────────────────────────────────────────────
     */

    /** @todo GET /api/products — 商品列表（待实现）*/
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product list']);
        // TODO 是开发者的约定标记，表示"这里还没写完，以后要补上"
        // IDE 和一些工具可以扫描所有 TODO，方便跟踪未完成的任务
    }

    /** @todo GET /api/products/{id} — 商品详情（待实现）*/
    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product detail', 'id' => $id]);
    }

    /** @todo PUT /api/products/{id} — 更新商品（待实现）*/
    public function update(int $id): JsonResponse
    {
        return response()->json(['message' => 'TODO: implement product update', 'id' => $id]);
    }

    /*
     * ──────────────────────────────────────────────
     * ★ 本次核心方法：import() Excel批量导入
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
     *
     * 📥 请求示例：
     *    POST /api/products/import
     *    Content-Type: multipart/form-data
     *    Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
     *
     *    file: [binary excel data]
     *
     * ══════════════════════════════════════════
     * 📤 响应场景一：全部成功 (201 Created)
     * ══════════════════════════════════════════
     * {
     *     "code": 0,
     *     "message": "导入完成，共成功导入 50 条商品。",
     *     "data": {
     *         "success_count": 50,
     *         "fail_count": 0,
     *         "errors": []
     *     }
     * }
     *
     * ══════════════════════════════════════════
     * 📤 响应场景二：部分成功 (200 OK)
     * ══════════════════════════════════════════
     * {
     *     "code": 0,
     *     "message": "部分导入成功。成功 47 条，失败 3 条。",
     *     "data": {
     *         "success_count": 47,
     *         "fail_count": 3,
     *         "errors": [
     *             {"row": 5,  "field": "price", "reason": "价格必须为正数"},
     *             {"row": 12, "field": "name",  "reason": "【商品名称】为必填项"},
     *             {"row": 28, "field": "stock", "reason": "库存不能为负数"}
     *         ]
     *     }
     * }
     *
     * ══════════════════════════════════════════
     * 📤 响应场景三：全部失败 (422 Unprocessable Entity)
     * ══════════════════════════════════════════
     * {
     *     "code": 422,
     *     "message": "导入失败，共 200 行数据有误。",
     *     "data": {
     *         "success_count": 0,
     *         "fail_count": 200,
     *         "errors": [ ... ]
     *     }
     * }
     */
    public function import(ImportProductsRequest $request): JsonResponse
    {
        // ════════════════════════════════════════
        // 步骤1：获取上传的文件
        // ════════════════════════════════════════

        /**
         * @var \Illuminate\Http\UploadedFile $file
         *
         * $request->file('file') 获取名为 'file' 的上传文件
         * 字段名必须与 FormRequest 中定义的规则一致
         *
         * 到这一步时，FormRequest 已经完成了以下校验：
         * ✅ file 字段是否存在（required）
         * ✅ 是否是有效的文件（file）
         * ✅ 文件格式是否允许（mimes:xlsx,xls,csv）
         * ✅ 文件大小是否在限制内（max:10240 = 10MB）
         *
         * 所以这里的 $file 一定是有效可用的
         */
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        // ════════════════════════════════════════
        // 步骤2：调用 Service 层执行导入逻辑
        // ════════════════════════════════════════

        /*
         * $importService->import($file) 内部流程（详见 ProductImportService）：
         *
         * ① IOFactory::load() 解析 Excel 文件
         * ② 检查行数上限（≤2000条数据）
         * ③ 读取并映射表头列名
         * ④ 逐行进行行级校验：
         *    - 必填项检查（name/category/type/price/stock 不能为空）
         *    - 价格 > 0 校验
         *    - 库存 ≥ 0 校验
         * ⑤ 收集合法数据和错误明细
         * ⑥ 开启事务，对合法数据执行 updateOrCreate（存在则更新，不存在则创建）
         * ⑦ 返回 { success_count, fail_count, errors } 结果
         *
         * ⚠️ 可能抛出的异常：
         *    - 文件无法解析 → InvalidArgumentException
         *    - 行数超限 → InvalidArgumentException
         *    - 表头无法识别 → InvalidArgumentException
         *    这些异常会被全局异常处理器捕获，统一返回 400/422 格式错误
         */
        $result = $this->importService->import($file);

        // ════════════════════════════════════════
        // 步骤3：根据结果选择 HTTP 状态码和消息
        // ════════════════════════════════════════

        /*
         * 🎯 为什么不同情况要用不同的状态码？
         *
         * RESTful API 设计规范建议：
         *    - 201 Created：成功创建了新资源（全部导入成功 = 创建了新记录）
         *    - 200 OK：请求被正确处理（部分成功也是"处理完毕"，只是有些警告）
         *    - 422 Unprocessable Entity：服务器理解请求但无法处理
         *      （全部失败 = 数据有问题，服务端无法将任何数据写入数据库）
         *
         * 💡 前端可以根据状态码做不同的 UI 展示：
         *    201 → 绿色提示："✅ 导入成功！"
         *    200 → 黄色提示："⚠️ 部分成功，以下X行有误..."
         *    422 → 红色提示："❌ 导入失败，请修改后重试"
         */
        if ($result['fail_count'] > 0 && $result['success_count'] === 0) {
            // 全部失败的情况
            $status = 422; // 422 Unprocessable Entity（不可处理的实体）
            $message = "导入失败，共 {$result['fail_count']} 行数据有误。";

        } elseif ($result['fail_count'] > 0 && $result['success_count'] > 0) {
            // 部分成功、部分失败的情况
            $status = 200; // 200 OK（请求已处理，只是有部分警告）
            $message = "部分导入成功。成功 {$result['success_count']} 条，失败 {$result['fail_count']} 条。";

        } else {
            // 全部成功的情况（fail_count === 0 且 success_count > 0）
            $status = 201; // 201 Created（成功创建了新资源）
            $message = "导入完成，共成功导入 {$result['success_count']} 条商品。";
        }

        // ════════════════════════════════════════
        // 步骤4：返回统一的 JSON 响应格式
        // ════════════════════════════════════════

        /*
         * 统一的 API 响应格式：
         * {
         *     "code": 0,           // 业务状态码（0=成功，其他值表示特定业务错误）
         *     "message": "...",    // 人可读的消息描述
         *     "data": { ... }      // 实际的业务数据
         * }
         *
         * 注意：code 和 HTTP status code 是两个不同的概念！
         *    - HTTP status code（$status）：HTTP 协议层面的状态（200/201/422...）
         *    - business code（'code'=>0）：应用业务层面的状态（0=成功）
         *    
         * 有些团队只用其中一个，我们这里两者都用：
         *    HTTP 状态码给中间件/浏览器/CDN 用
         *    业务 code 给前端业务逻辑判断用
         */
        return response()->json([
            'code'    => 0,
            'message' => $message,

            'data' => [
                'success_count' => $result['success_count'], // 成功导入的商品数量
                'fail_count'    => $result['fail_count'],    // 失败/跳过的行数
                'errors'        => $result['errors'],        // 错误明细数组（前端可用于高亮显示问题行）
                // errors 结构：[{row: 5, field: 'price', reason: '价格必须为正数'}, ...]
            ],
        ], $status); // 使用上面计算得出的动态状态码
    }
}
