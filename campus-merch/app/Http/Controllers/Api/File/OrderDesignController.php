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
     * @param  Order                       $order   订单模型（路由模型绑定自动解析）
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
    public function store(Order $order, UploadOrderDesignRequest $request): JsonResponse
    {
        // ════════════════════════════════════════
        // 步骤1：校验订单归属权限（安全防护）
        // ════════════════════════════════════════

        /*
         * 🔒 使用 OrderPolicy 的 view 策略校验数据归属权
         *    确保当前登录用户是订单的所有者，否则返回 403
         *    （与 OrderController::complete() 等方法保持一致的权限模式）
         *
         *    路由模型绑定已自动按 {order} 参数从数据库解析出 Order 实例，
         *    找不到时 Laravel 自动返回 404
         */
        $this->authorize('view', $order);

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
            'code'    => 0,
            'message' => '设计稿上传成功，订单已进入待审核状态。',

            'data' => [
                '附件编号'     => $attachment->id,
                '订单编号'     => $attachment->order_id,
                '文件名称'     => $this->cleanFileName($attachment->origin_name, $attachment->ext),
                '文件类型'     => $attachment->ext,
                '文件大小'     => $this->formatSize($attachment->size),
                '预览链接'     => app(\App\Services\File\OssUploadService::class)
                    ->getTemporaryUrl($attachment->storage_path),
                '订单状态'     => $order->fresh()->status?->value ?? (string)$order->fresh()->status,
            ],
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 格式化文件大小（字节 → 可读字符串）
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . $units[$i];
    }

    /**
     * 清理文件名：过滤乱码、空名称等异常情况
     */
    private function cleanFileName(?string $originName, string $ext): string
    {
        if (empty($originName)) {
            return "设计稿." . $ext;
        }

        // 去掉首尾空白和特殊符号后，如果只剩点号或极短字符，视为无效文件名
        $cleaned = trim($originName, " \t\n\r\0\x0B·.-_");
        if (mb_strlen($cleaned) < 2) {
            return "设计稿." . $ext;
        }

        // 如果原始文件名只包含标点/符号，也视为无效
        if (preg_match('/^[\s\.\-\_\·]+$/', $originName)) {
            return "设计稿." . $ext;
        }

        return $originName;
    }
}
