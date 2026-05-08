<?php

/**
 * ============================================================================
 * 【接口3控制器】订单报表导出控制器
 * ============================================================================
 *
 * 📌 功能概述：
 *    处理 GET /api/orders/export 接口请求，
 *    接收前端传来的筛选条件（query参数），调用 Service 层生成 Excel 流式下载。
 *
 * 🔗 路由对应：
 *    GET /api/orders/export → OrderExportController::export()
 *
 * 💡 控制器的职责（极简设计）：
 *    这个控制器非常简洁——它只做两件事：
 *    1. 从 URL 的 query 参数中提取筛选条件
 *    2. 把筛选条件传给 Service 层，返回 StreamedResponse 流式响应
 *
 *    为什么这么简洁？因为复杂的逻辑（查询构建、Excel生成、流式输出）
 *    都在 OrderExportService 中完成了，控制器不需要关心这些细节。
 *
 * 📖 相关概念：
 *    - StreamedResponse：Symfony 提供的流式响应类，用于大文件/长内容输出
 *    - Query Parameters：URL 中 ? 后面的键值对参数（如 ?status=completed&keyword=T恤）
 *    - array_filter()：PHP 内置函数，用于过滤数组中的空值/null/false 等
 */

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;        // 基础控制器
use App\Services\Report\OrderExportService; // 订单导出服务（业务逻辑层）
use Illuminate\Http\Request;                // Laravel HTTP 请求对象
use Symfony\Component\HttpFoundation\StreamedResponse; // Symfony 流式响应

class OrderExportController extends Controller
{
    /**
     * 依赖注入 —— 注入订单导出服务
     */
    public function __construct(
        private readonly OrderExportService $exportService // 导出服务实例
    ) {}

    /**
     * GET /api/orders/export
     * 管理员按筛选条件导出订单 Excel 报表（流式下载）
     *
     * 🔄 请求处理流程：
     *
     *    管理员浏览器
     *       ↓ GET /api/orders/export?status=completed&start_date=2026-01-01&keyword=T恤
     *       ↓ Headers: Authorization: Bearer {admin_token}
     *    ┌──────────────────┐
     *    │ ① 提取筛选条件    │ ← 从 URL query 参数解析筛选项
     *    └────┬─────────────┘
     *       ↓
     *    ┌──────────────────┐
     *    │ ② 调用 Service    │ ← $exportService->export($filters)
     *    │    生成Excel并     │   返回 StreamedResponse（流式响应）
     *    │    流式输出        │
     *    └────┬─────────────┘
     *       ↓
     *    ┌──────────────────┐
     *    │ ③ 浏览器接收      │ ← 自动弹出"另存为"对话框或开始下载
     *    │    文件下载        │   文件名：orders_export_2026_04_28_183000.xlsx
     *    └──────────────────┘
     *
     * @param  Request          $request  Laravel HTTP 请求对象（包含 query 参数等）
     * @return  StreamedResponse           Symfony 流式响应（浏览器直接收到文件下载）
     *
     * 📥 请求示例：
     *    GET /api/orders/export?status=booked&start_date=2026-03-01&end_date=2026-04-28&keyword=校园T恤
     *    Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
     *
     * 📤 响应（不是 JSON！是二进制 Excel 文件流）：
     *    HTTP/1.1 200 OK
     *    Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
     *    Content-Disposition: attachment; filename="orders_export_2026_04_28_183000.xlsx"
     *    Cache-Control: max-age=0
     *
     *    [binary xlsx data stream...]
     *
     * ⚠️ 注意：响应体是**二进制文件数据**，不是 JSON！
     *    因为 Content-Type 是 application/vnd...spreadsheetml.sheet，
     *    浏览器会自动触发文件下载而不是尝试解析为 JSON。
     */
    public function export(Request $request): StreamedResponse
    {
        // ════════════════════════════════════════
        // 步骤1：从 URL query 参数提取筛选条件
        // ════════════════════════════════════════

        /*
         * 📍 Query Parameter 是什么？
         *    就是 URL 中 "?" 后面的键值对部分。
         *    例：/api/orders/export?status=booked&keyword=T恤&start_date=2026-01-01
         *        ─────────────────────────────────┬────────────────────────────
         *                              这部分就是 query string（查询字符串）
         *
         * 💡 $request->query('key') 的作用：
         *    从 query string 中获取指定参数的值。
         *    如果参数不存在则返回 null。
         *
         * 支持的筛选条件：
         *    - status:     订单状态码（如 'draft', 'booked', 'completed' 等）
         *    - start_date: 开始日期（如 '2026-01-01'）—— 导出此日期及之后的订单
         *    - end_date:   结束日期（如 '2026-04-28'）—— 导出此日期及之前的订单
         *    - product_id: 商品ID（如 '42'）—— 只导出该商品的订单
         *    - keyword:    搜索关键词（如 '校园T恤'）—— 模糊匹配用户名/商品名/备注/地址
         */

        /*
         * array_filter() 过滤空值
         *
         * 🎯 为什么需要过滤？
         *    用户可能没有传某个筛选条件（比如只想按状态筛选，不限定日期），
         *    这时候没传的参数值会是 null 或空字符串 ''。
         *    如果把这些空值也传给 Service，可能会导致不必要的 SQL WHERE 条件。
         *
         * array_filter($arr, callback) 遍历数组的每个元素，
         * 只有当 callback 返回 true 时才保留该元素。
         *
         * 我们的回调：fn ($v) => $v !== null && $v !== ''
         *    意思是：只有值不为 null 且 不为空字符串时才保留
         *
         * 💡 示例：
         *    输入：['status'=>'booked', 'start_date'=>null, 'end_date'=>'', 'product_id'=>null, 'keyword'=>'T恤']
         *    输出：['status'=>'booked', 'keyword'=>'T恤']
         *           ↑ start_date/end_date/product_id 被过滤掉了（因为它们是 null 或 ''）
         */
        $filters = array_filter([
            'status'     => $request->query('status'),      // 状态筛选
            'start_date' => $request->query('start_date'),  // 开始日期
            'end_date'   => $request->query('end_date'),    // 结束日期
            'product_id' => $request->query('product_id'),  // 商品ID
            'keyword'    => $request->query('keyword'),     // 关键词搜索
        ], fn ($v) => $v !== null && $v !== '');

        // ════════════════════════════════════════
        // 步骤2：调用 Service 并返回流式响应
        // ════════════════════════════════════════

        /*
         * $exportService->export($filters) 会做以下事情（详见 OrderExportService）：
         *
         * ① 构建 Eloquent 查询（带 where 条件、预加载关联、关键词模糊匹配）
         * ② 创建 PhpSpreadsheet Spreadsheet 对象
         * ③ 写入蓝底白字样式的中文表头（14列）
         * ④ chunk(200) 分块查询订单数据 → 逐行写入 Excel
         *    每500行触发一次 gc_collect_cycles() 回收内存
         * ⑤ 设置固定列宽 + 冻结首行
         * ⑥ 通过 $writer->save('php://output') 流式输出到浏览器
         * ⑦ 返回 StreamedResponse 对象
         *
         * 控制器只需 return 这个 StreamedResponse 即可！
         * Laravel/Symfony 会自动把响应发送给浏览器。
         *
         * 🌊 浏览器收到后的行为：
         *    看到 Content-Disposition: attachment 头 → 触发"另存为"/下载操作
         *    看到 Content-Type: ...spreadsheetml.sheet → 知道这是 Excel 文件
         *    看到 Cache-Control: max-age=0 → 不缓存此下载
         */
        return $this->exportService->export($filters);
    }
}
