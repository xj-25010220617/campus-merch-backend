<?php

/**
 * ============================================================================
 * 【接口2相关】定制稿上传服务 — 订单设计稿/图案附件管理
 * ============================================================================
 *
 * 📌 功能概述：
 *    专门处理订单设计稿（定制图案）的上传、查询和管理。
 *    在 OssUploadService（基础文件存储）之上，增加了业务逻辑：
 *    - 订单状态机校验（只有已预订的订单才能上传设计稿）
 *    - 文件元数据落库（记录到 order_attachments 表）
 *    - 上传后自动流转订单状态
 *
 * 🔗 对接接口：
 *    POST /api/orders/{id}/design → OrderDesignController::store() → 本服务
 *
 * 💡 核心知识点：
 *    1. 状态机模式：订单状态只能按预定规则流转，防止非法操作
 *    2. 数据库事务：确保"保存附件记录 + 更新订单状态"原子性操作
 *    3. 逻辑删除：不真正删除数据库记录，而是标记 is_deleted=true
 *    4. 依赖注入：通过构造函数注入 OssUploadService（解耦 + 方便测试）
 *
 * 📖 相关概念：
 *    - 状态机：一个模型的状态按规则转换，如 booked→design_pending
 *    - 事务（Transaction）：一组 SQL 操作，要么全部成功，要么全部回滚
 *    - 逻辑删除 vs 物理删除：标记删除 vs 真正从数据库移除
 */

namespace App\Services\File;

use App\Enums\OrderStatus;           // 订单状态枚举（定义所有合法状态及流转规则）
use App\Models\Order;                 // 订单 Model（对应 orders 表）
use App\Models\OrderAttachment;       // 订单附件 Model（对应 order_attachments 表）
use Illuminate\Http\UploadedFile;     // Laravel 上传文件封装类

class OrderAttachmentService
{
    /*
     * ──────────────────────────────────────────────
     * 第一部分：依赖注入构造函数
     * ──────────────────────────────────────────────
     */

    /**
     * 构造函数 —— Laravel 自动依赖注入
     *
     * 🎯 什么是依赖注入（DI）？
     *    不在类内部 `new OssUploadService()`，而是通过构造函数参数"声明需要什么"，
     *    Laravel 容器会自动帮你创建并传入实例。
     *
     * ✅ 好处：
     *    1. 解耦 —— 本类不需要知道 OssUploadService 怎么创建的
     *    2. 可测试 —— 单元测试时可以轻松替换为 Mock 对象
     *    3. 单例管理 —— Laravel 会自动管理 Service 的生命周期
     *
     * 🔍 private readonly 的含义：
     *    private   → 只能在类内部访问（封装性）
     *    readonly  → PHP 8.1+ 特性：赋值后不可修改（不可变性）
     *    合起来：这是一个私有且赋值后不可修改的属性
     *
     * @param OssUploadService $ossService  文件上传基础服务（由 Laravel 容器自动注入）
     */
    public function __construct(
        private readonly OssUploadService $ossService
    ) {}

    /*
     * ──────────────────────────────────────────────
     * 第二部分：核心方法 - uploadDesign() 上传设计稿
     * ──────────────────────────────────────────────
     */

    /**
     * 为订单上传设计稿/定制图案
     *
     * 🔄 完整业务流程：
     *
     *    ┌──────────────┐
     *    │ 1. 校验状态   │ ← 检查订单是否为 "已预订(booked)" 状态
     *    └──────┬───────┘
     *           ↓ 通过
     *    ┌──────────────┐
     *    │ 2. 上传文件   │ ← 调用 OssUploadService 存到磁盘/OSS
     *    └──────┬───────┘
     *           ↓ 返回路径等信息
     *    ┌──────────────┐
     *    │ 3. 开启事务   │ ← DB::transaction() 包裹后续操作
     *    └──────┬───────┘
     *           ↓
     *    ┌──────────────┐
     *    │ 4a.存附件记录│ ← 写入 order_attachments 表
     *    ├──────────────┤
     *    │ 4b.更新状态  │ ← 订单 booked → design_pending
     *    └──────┬───────┘
     *           ↓ 全部成功
     *    ┌──────────────┐
     *    │ 5. 提交事务   │ ← 数据永久保存到数据库
     *    └──────────────┘
     *
     * ❌ 如果步骤4中任何一步失败：
     *    → 自动回滚（rollback），附件和状态都不会被保存
     *
     * ⚙️ 状态机约束说明：
     *    订单生命周期（简化版）：
     *      draft(草稿) → booked(已预订) → design_pending(待审核)
     *                                               ↓
     *                                         ready(制作中) → completed(已完成)
     *                                               ↓
     *                                         rejected(已驳回)
     *
     *    只有 booked 状态的订单才允许上传设计稿！其他状态会抛出异常。
     *
     * @param  Order        $order  订单模型实例（必须是从 DB 查出的真实记录）
     * @param  UploadedFile $file   用户上传的文件对象
     * @return OrderAttachment      创建成功的附件模型实例（包含 id、path 等信息）
     *
     * @throws \InvalidArgumentException 当订单状态不允许上传时抛出
     *
     * 💡 调用示例：
     *    $order = Order::find(123);
     *    $attachment = $attachmentService->uploadDesign($order, $request->file('file'));
     *    echo $attachment->id;         // 附件ID
     *    echo $attachment->storage_path; // 存储路径
     */
    public function uploadDesign(Order $order, UploadedFile $file): OrderAttachment
    {
        // ════════════════════════════════════════
        // 步骤1：状态机校验
        // ════════════════════════════════════════

        /*
         * 📖 OrderStatus 是什么？
         *    PHP 枚举类（enum），定义了订单的所有合法状态常量。
         *    例：OrderStatus::BOOKED->value 返回字符串 'booked'
         *
         * 🔍 ->value 和 ->name 的区别：
         *    ->value = 枚举对应的值（如 'booked'）
         *    ->name  = 枚举常量名（如 'BOOKED'）
         */
        if ($order->status !== OrderStatus::BOOKED->value) {
            throw new \InvalidArgumentException(
                "当前订单状态为「{$order->status}」，仅「已预订」状态的订单可上传设计稿。"
                // 抛出异常后，Controller 的异常处理器会捕获并返回友好的错误响应
            );
        }

        // ════════════════════════════════════════
        // 步骤2：调用 OssUploadService 上传文件
        // ════════════════════════════════════════

        /*
         * 📁 目录命名策略：
         *    "merch-designs/{order_id}" 意思是：
         *    - 所有设计稿统一放在 merch-designs 根目录下
         *    - 按订单 ID 分子目录，便于管理和清理
         *
         *    最终文件路径类似：
         *    merch-designs/42/1740480000_a1b2c3d4-e5f6-7890-abcd-ef1234567890.png
         *
         * 📦 $uploadResult 返回值结构：
         *    [
         *        'path' => 'merch-designs/42/xxx.png',  // 存储路径
         *        'url'  => 'https://...',                // 访问URL
         *        'name' => '我的设计稿.png',             // 原始文件名
         *        'mime' => 'image/png',                  // MIME类型
         *        'size' => 1234567,                      // 文件大小(字节)
         *        'ext'  => 'png',                        // 扩展名
         *    ]
         */
        $uploadResult = $this->ossService->upload($file, "merch-designs/{$order->id}");

        // ════════════════════════════════════════
        // 步骤3~5：数据库事务 —— 原子化写入
        // ════════════════════════════════════════

        /*
         * 🔄 什么是数据库事务（Transaction）？
         *    把多个数据库操作捆绑在一起，保证：
         *    - 要么全部执行成功（commit）
         *    - 要么全部失败回滚（rollback）
         *
         * 💡 为什么这里要用事务？
         *    因为有**两个**写操作必须同时成功或同时失败：
         *    1️⃣ 创建 order_attachments 记录（保存文件信息）
         *    2️⃣ 更新 orders.status（改变订单状态）
         *
         *    如果只完成了①但②失败了，会出现：
         *    "附件存在了，但订单状态还是 booked" 的不一致问题！
         *
         * 📝 DB::transaction() 语法：
         *    接收一个闭包（匿名函数），闭包内抛出异常则自动 rollback，
         *    正常结束则自动 commit。
         *
         * 🔗 use ($order, $uploadResult) 是什么？
         *    闭包变量绑定——让闭包内部能访问外部的 $order 和 $uploadResult 变量。
         *    & 引用传递（&$insertedCount）允许闭包内部修改外部变量的值。
         */
        return \DB::transaction(function () use ($order, $uploadResult) {

            /*
             * 📝 操作4a：创建附件记录（写入 order_attachments 表）
             *
             * OrderAttachment::create() 是 Eloquent ORM 的批量创建方法，
             * 相当于执行 SQL：
             *   INSERT INTO order_attachments (order_id, type, origin_name, ...)
             *   VALUES (..., 'design', '我的设计稿.png', ...)
             *
             * 📋 各字段含义：
             *    order_id    → 关联哪个订单（外键）
             *    type        → 附件类型：'design'=设计稿, 未来可扩展 'contract'=合同等
             *    origin_name → 用户原始文件名（用于显示）
             *    storage_path→ 文件在存储系统中的路径（用于读取/删除）
             *    mime_type   → 文件MIME类型
             *    size        → 文件大小（字节）
             *    ext         → 扩展名
             *    is_deleted  → 逻辑删除标记（false=未删除）
             *
             * @var 注释是 PHPDoc 的类型提示，帮助 IDE 识别变量类型
             */
            /** @var OrderAttachment $attachment */
            $attachment = OrderAttachment::create([
                'order_id'    => $order->id,
                'type'        => 'design',
                'origin_name' => $uploadResult['name'],
                'storage_path'=> $uploadResult['path'],
                'mime_type'   => $uploadResult['mime'],
                'size'        => $uploadResult['size'],
                'ext'         => $uploadResult['ext'],
                'is_deleted'  => false,
            ]);

            /*
             * 📝 操作4b：更新订单状态（写入 orders 表）
             *
             * $order->update() 是 Eloquent 的更新方法，
             * 相当于执行 SQL：
             *   UPDATE orders SET status = 'design_pending' WHERE id = ?
             *
             * 🔄 状态变化：
             *    booked（已预订） → design_pending（待审核/待确认设计稿）
             *
             * 这个状态变化意味着：
             *    用户已提交了设计稿，等待管理员/运营人员审核确认。
             */
            $order->update(['status' => OrderStatus::DESIGN_PENDING->value]);

            // 返回新创建的附件记录给调用方
            return $attachment;
        });
        // ↑ 事务到此结束。如果闭包内无异常 → commit；有异常 → rollback
    }

    /*
     * ──────────────────────────────────────────────
     * 第三部分：辅助方法 - markAsDeleted() 逻辑删除
     * ──────────────────────────────────────────────
     */

    /**
     * 标记附件为逻辑删除（订单驳回时调用）
     *
     * 🗑️ 什么是逻辑删除（Soft Delete / Logical Delete）？
     *    不是用 DELETE FROM 真正删除数据库记录，
     *    而是把 is_deleted 字段设为 true，查询时过滤掉已删除的记录。
     *
     * ✅ 逻辑删除的好处：
     *    1. 可恢复 —— 误删后可以把 is_deleted 改回来
     *    2. 可追溯 —— 能看到历史附件记录（审计需求）
     *    3. 数据安全 —— 避免级联删除导致的数据丢失
     *    4. 外键安全 —— 不会因为删除记录导致关联数据出问题
     *
     * ❌ 物理删除（DELETE FROM）的问题：
     *    删了就没了！无法恢复，可能导致引用断裂。
     *
     * @param  OrderAttachment  $attachment  要删除的附件模型实例
     * @param  string           $_reason     删除原因（预留参数，当前未使用）
     *                                      下划线前缀 _reason 表示"有意忽略此参数"
     *                                      避免 IDE 报 "unused parameter" 警告
     * @return bool  更新是否成功
     *
     * 💡 使用场景：
     *    管理员驳回了用户的设计稿时，把旧的设计稿标记为已删除，
     *    用户重新上传新的设计稿。
     */
    public function markAsDeleted(OrderAttachment $attachment, string $_reason = ''): bool
    {
        return $attachment->update([
            'is_deleted' => true,
            // SQL: UPDATE order_attachments SET is_deleted = true WHERE id = ?
        ]);
    }

    /*
     * ──────────────────────────────────────────────
     * 第四部分：辅助方法 - getValidAttachments() 查询有效附件
     * ──────────────────────────────────────────────
     */

    /**
     * 获取订单的有效附件列表（含临时访问链接）
     *
     * 📂 查询逻辑：
     *    1. 找到该订单的所有附件（$order->attachments() 是一对多关系）
     *    2. 过滤掉已逻辑删除的（is_deleted = false）
     *    3. 为每个附件生成临时访问链接
     *    4. 返回数组格式
     *
     * 🔗 Eloquent 关系说明：
     *    $order->attachments() 定义在 Order Model 中，是 hasMany 关系：
     *      public function attachments() { return $this->hasMany(OrderAttachment::class); }
     *    它等价于 SQL: SELECT * FROM order_attachments WHERE order_id = ? AND is_deleted = 0
     *
     * 📊 map() 方法：
     *    Laravel Collection 的 map 方法，遍历每个元素并通过回调函数转换它。
     *    类似 JavaScript 的 Array.map()
     *
     * array_merge($att->toArray(), [...]) 的作用：
     *    把原始附件数据和新增的 temporary_url 字段合并为一个数组。
     *
     * @param  Order  $order  订单模型实例
     * @return array  附件数组，每项包含原字段 + temporary_url
     *
     * 📤 返回值示例：
     *    [
     *        [
     *            'id' => 1,
     *            'origin_name' => '设计稿V1.png',
     *            'storage_path' => 'merch-designs/42/xxx.png',
     *            ...
     *            'temporary_url' => 'http://...'  // ← 新增字段
     *        ],
     *        ...
     *    ]
     */
    public function getValidAttachments(Order $order): array
    {
        /*
         * 链式调用解析：
         *
         * $order->attachments()
         *     → 获取该订单的附件关系查询构建器
         *
         * ->where('is_deleted', false)
         *     → 添加 WHERE 条件：只要未删除的
         *
         * ->get()
         *     → 执行查询，返回 Collection 集合（不是数组！Collection 有更多好用方法）
         *
         * ->map(fn ($att) => ...)
         *     → 遍历集合中的每个附件，进行转换
         *     fn () 是 PHP 7.4+ 的箭头函数语法糖（简化的匿名函数）
         *
         * ->toArray()
         *     → 最终转为普通 PHP 数组
         */
        return $order->attachments()
            ->where('is_deleted', false)
            ->get()
            ->map(fn (OrderAttachment $att) => array_merge($att->toArray(), [
                // 为每个附件额外添加临时访问链接
                'temporary_url' => $this->ossService->getTemporaryUrl($att->storage_path),
                // 调用 OssUploadService 的 getTemporaryUrl 方法生成时效链接
            ]))
            ->toArray();
    }
}
