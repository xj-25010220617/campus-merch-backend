<?php

/**
 * ============================================================================
 * 【接口2控制器】订单设计稿上传控制器
 * ============================================================================
 *
 * 📌 功能概述：
 *    处理 POST /api/orders/{id}/design 接口请求，
 *    负责接收文件、校验权限、调用 Service 层处理业务逻辑、返回响应。
 *
 * 🔗 路由对应：
 *    POST /api/orders/{id}/design → OrderDesignController::store()
 *
 * 💡 控制器（Controller）的职责：
 *    控制器是 MVC 模式中的 "C"，它的职责是"协调者"：
 *    1. 接收 HTTP 请求（参数、文件、用户信息等）
 *    2. 调用 Service 层执行业务逻辑（不在这里写复杂业务代码！）
 *    3. 返回 HTTP 响应（JSON 格式的 API 响应）
 *
 * ⚠️ 控制器应该保持简洁（Thin Controller）：
 *    业务逻辑放在 Service 中，Controller 只负责"接收请求 → 调用Service → 返回结果"
 *
 * 📖 相关概念：
 *    - FormRequest：Laravel 的表单请求验证类，在进入控制器方法前自动校验
 *    - JsonResponse：标准的 JSON 格式 HTTP 响应
 *    - 依赖注入：通过构造函数自动注入所需的 Service
 */

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;                          // 基础控制器（提供通用方法）
use App\Http\Requests\File\UploadOrderDesignRequest;         // 设计稿上传的表单验证请求类
use App\Models\Order;                                        // 订单 Model
use App\Services\File\OrderAttachmentService;                // 定制稿上传服务（业务逻辑层）
use Illuminate\Http\JsonResponse;                            // JSON 响应类

class OrderDesignController extends Controller
{
    /*
     * ──────────────────────────────────────────────
     * 依赖注入构造函数
     * ──────────────────────────────────────────────
     *
     * Laravel 在创建这个控制器实例时，会自动检测构造函数的参数类型，
     * 并从服务容器中解析并注入对应的实例。
     *
     * 这意味着我们不需要手动 new OrderAttachmentService()，
     * Laravel 会自动帮我们完成！
     */
    public function __construct(
        private readonly OrderAttachmentService $attachmentService // 注入定制稿上传服务
    ) {}

    /**
     * POST /api/orders/{id}/design
     * 为已预订订单上传定制设计稿/图案
     *
     * 🔄 请求处理流程：
     *
     *    用户浏览器/APP
     *       ↓ POST /api/orders/{id}/design (multipart/form-data)
     *       ↓ Headers: Authorization: Bearer {token}
     *       ↓ Body: design_file=(二进制文件)
     *    ┌──────────────────┐
     *    │ ① UploadOrder-   │ ← FormRequest 自动验证（文件是否存在、MIME、大小等）
     *    │    DesignRequest   │    如果验证失败，直接返回 422 错误，不会进入本方法！
     *    └────┬─────────────┘
     *       ↓ 验证通过
     *    ┌──────────────────┐
     *    │ ② 查询订单        │ ← 确保订单存在 且 归属于当前登录用户（权限校验）
     *    └────┬─────────────┘
     *       ↓ 订单存在且属于当前用户
     *    ┌──────────────────┐
     *    │ ③ 调用 Service    │ ← $attachmentService->uploadDesign()
     *    │    执行业务逻辑    │   （状态机校验→上传文件→落库→状态流转）
     *    └────┬─────────────┘
     *       ↓ 成功或异常
     *    ┌──────────────────┐
     *    │ ④ 返回 JSON 响应  │ ← 201 Created + 附件信息 + 临时链接
     *    └──────────────────┘
     *
     * @param  int                        $id      订单ID（从URL路径参数获取）
     * @param  UploadOrderDesignRequest   $request  经过验证的表单请求对象
     * @return  JsonResponse                      JSON 格式的API响应
     *
     * 📥 请求示例：
     *    POST /api/orders/42/design
     *    Content-Type: multipart/form-data
     *    Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
     *    
     *    design_file: [binary file data]
     *
     * 📤 成功响应（201 Created）：
     * {
     *     "code": 0,
     *     "message": "设计稿上传成功，订单已进入待审核状态。",
     *     "data": {
     *         "id": 15,
     *         "order_id": 42,
     *         "origin_name": "我的设计稿.psd",
     *         "mime_type": "image/vnd.adobe.photoshop",
     *         "size": 15728640,
     *         "ext": "psd",
     *         "temporary_url": "https://...",
     *         "order_status": "design_pending"
     *     }
     * }
     *
     * 📤 错误响应示例：
     *    - 404: 订单不存在或不属于当前用户
     *    - 413: 文件太大（FormRequest 校验）
     *    - 422: 文件格式不支持 / 当前状态不允许上传设计稿
     */
    public function store(int $id, UploadOrderDesignRequest $request): JsonResponse
    {
        // ════════════════════════════════════════
        // 步骤1：查询并校验订单归属（安全防护）
        // ════════════════════════════════════════

        /*
         * 🔒 为什么需要 where('user_id', ...) 条件？
         *    这是一个关键的安全措施 —— **数据归属权校验**。
         *
         *    URL中的 $id 只是告诉我们要操作哪个订单，
         *    但我们必须确保这个订单**归属于当前登录用户**，
         *    否则恶意用户可以修改别人的订单（越权攻击！）。
         *
         *    $request->user() 从 JWT/Session 中获取当前认证的用户模型
         *
         * firstOrFail() 说明：
         *    如果找到记录 → 返回 Order 模型实例
         *    如果没找到 → 自动抛出 ModelNotFoundException → 全局异常处理器返回 404
         *
         * ⚠️ 这里的查询条件是 AND 关系：
         *    WHERE id = ? AND user_id = ?
         *    必须同时满足：ID匹配 + 属于当前用户
         */
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // ════════════════════════════════════════
        // 步骤2：获取上传的文件
        // ════════════════════════════════════════

        /**
         * @var \Illuminate\Http\UploadedFile $file
         *
         * $request->file('design_file') 获取名为 'design_file' 的上传文件
         * 这个字段名必须与前端 <input name="design_file"> 或 formData.append('design_file', file) 一致
         *
         * @var 注解帮助 IDE 识别变量的真实类型，提供代码提示和类型检查
         */
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('design_file');

        // ════════════════════════════════════════
        // 步骤3：调用 Service 层执行核心业务逻辑
        // ════════════════════════════════════════

        /*
         * uploadDesign() 内部做了这些事（详见 OrderAttachmentService）：
         *    ✅ 校验订单状态是否为 booked
         *    ✅ 调用 OssUploadService 上传文件（MIME/大小/扩展名三重校验）
         *    ✅ 开启数据库事务
         *    ✅ 写入 order_attachments 表（元数据落库）
         *    ✅ 更新订单状态为 design_pending
         *    ✅ 提交事务
         *
         * 如果任何步骤失败，会抛出异常，由全局异常处理器捕获并返回错误响应
         */
        $attachment = $this->attachmentService->uploadDesign($order, $file);

        // ════════════════════════════════════════
        // 步骤4：构建并返回成功响应
        // ════════════════════════════════════════

        return response()->json([
            'code' => 0,
            'message' => '设计稿上传成功，订单已进入待审核状态。',
            
            'data' => [
                'id'           => $attachment->id,           // 附件记录 ID
                'order_id'     => $attachment->order_id,     // 所属订单 ID
                'origin_name'  => $attachment->origin_name,  // 用户原始文件名（用于显示）
                'mime_type'    => $attachment->mime_type,    // MIME 类型
                'size'         => $attachment->size,         // 文件大小（字节）
                'ext'          => $attachment->ext,          // 扩展名

                /*
                 * 生成临时访问链接
                 *
                 * app() 是 Laravel 的服务容器解析方法
                 * app(OssUploadService::class) 从容器中获取 OssUploadService 实例
                 *
                 * 这里没有使用注入的 $this->attachmentService（因为它内部有 ossService），
                 * 而是直接从容器取 OssUploadService 来调用 getTemporaryUrl()
                 * （也可以把 OssUploadService 也注入到构造函数中）
                 */
                'temporary_url' => app(\App\Services\File\OssUploadService::class)
                    ->getTemporaryUrl($attachment->storage_path),

                /*
                 * $order->fresh() 重新从数据库读取最新的订单状态
                 *
                 * 为什么需要 fresh()？
                 *    因为 uploadDesign() 内部已经更新了 order->status（booked → design_pending），
                 *    但内存中的 $order 对象还是旧的状态（Laravel Eloquent 有缓存机制）。
                 *    fresh() 会丢弃缓存，重新从 DB 查询一次最新数据。
                 */
                'order_status' => $order->fresh()->status,
            ],
        ], 201); // HTTP 状态码 201 = Created（资源创建成功）
    }
}
