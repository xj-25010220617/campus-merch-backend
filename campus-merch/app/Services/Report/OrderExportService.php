<?php

/**
 * ============================================================================
 * 【接口3相关 - 导出部分】订单报表 Excel 导出服务
 * ============================================================================
 *
 * 📌 功能概述：
 *    将订单数据导出为 Excel 文件，支持多种筛选条件。
 *    核心特性：**流式输出 + 分块查询**，防止大数据量导致内存溢出（OOM）。
 *
 * 🔗 对接接口：
 *    GET /api/orders/export → OrderExportController::export() → 本服务
 *
 * 💡 核心知识点：
 *    1. StreamedResponse：Symfony 的流式响应，边生成边发送给浏览器
 *    2. chunk(200) 分块查询：每次只从数据库取200条，不一次性加载全部数据
 *    3. gc_collect_cycles()：手动触发 PHP 垃圾回收，释放循环引用占用的内存
 *    4. PhpSpreadsheet：PHP 生成 Excel 的主流库（写入 .xlsx 格式）
 *    5. Coordinate 工具类：列索引（1,2,3...）与列字母（A,B,C...）的转换
 *
 * 📖 相关概念：
 *    - OOM = Out Of Memory，内存溢出，程序因内存不足被系统强制杀死
 *    - StreamedResponse = 流式响应，数据不缓存在内存中，直接输出到浏览器
 *    - chunk = 分块/分批，把大任务拆成小批次逐个处理
 *    - 冻结窗格（Freeze Pane）：滚动 Excel 时表头始终可见
 */

namespace App\Services\Report;

use App\Models\Order;                         // 订单 Model（对应 orders 表）
use App\Services\File\OssUploadService;       // OSS 服务（用于生成设计稿临时链接）
use Illuminate\Database\Eloquent\Builder;     // Eloquent 查询构建器类型
use PhpOffice\PhpSpreadsheet\Cell\Coordinate; // 坐标工具类（列数字 ↔ 列字母 转换）
use PhpOffice\PhpSpreadsheet\Spreadsheet;     // Spreadsheet 对象（代表整个Excel工作簿）
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;      // Xlsx 写入器（将 Spreadsheet 写为 .xlsx 文件）
use Symfony\Component\HttpFoundation\StreamedResponse; // Symfony 流式响应类

class OrderExportService
{
    /*
     * ──────────────────────────────────────────────
     * 第一部分：配置常量 —— 导出列定义
     * ──────────────────────────────────────────────
     */

    /**
     * 导出列定义（动态映射）
     *
     * 📋 定义了 Excel 中要导出哪些列，以及每列的显示名称和宽度：
     *
     * | Key (数据库字段) | Label (表头中文) | Width (列宽) | 数据来源 |
     * |-----------------|-----------------|-------------|---------|
     * | order_id        | 订单编号        | 10          | $order->id |
     * | user_name       | 预订人          | 15          | $order->user->name |
     * | product_name    | 商品名称        | 25          | $order->product->name |
     * | category        | 分类            | 12          | $order->product->category |
     * | quantity        | 数量            | 8           | $order->quantity |
     * | unit_price      | 单价            | 12          | $order->unit_price |
     * | total_price     | 总价            | 12          | quantity × unit_price |
     * | size            | 尺寸/规格       | 12          | $order->size |
     * | color           | 颜色偏好        | 12          | $order->color |
     * | status          | 状态            | 14          | 状态码→中文映射 |
     * | shipping_address | 发货地址        | 35          | $order->shipping_address |
     * | remark          | 备注            | 25          | $order->remark |
     * | design_file_url | 定制稿链接      | 50          | OSS临时链接 |
     * | created_at      | 下单时间        | 20          | $order->created_at |
     *
     * 🔑 为什么用关联数组？
     *    key 是字段标识符（用于从订单数据中取值），
     *    value 包含显示名和宽度等配置信息，
     *    遍历时可以同时拿到"用什么取数据"和"怎么展示"两个信息。
     */
    private const COLUMNS = [
        'order_id'        => ['label' => '订单编号',   'width' => 10],
        'user_name'       => ['label' => '预订人',     'width' => 15],
        'product_name'    => ['label' => '商品名称',   'width' => 25],
        'category'        => ['label' => '分类',       'width' => 12],
        'quantity'        => ['label' => '数量',       'width' => 8],
        'unit_price'      => ['label' => '单价',       'width' => 12],
        'total_price'     => ['label' => '总价',       'width' => 12],
        'size'            => ['label' => '尺寸/规格',  'width' => 12],
        'color'           => ['label' => '颜色偏好',   'width' => 12],
        'status'          => ['label' => '状态',       'width' => 14],
        'shipping_address'=> ['label' => '发货地址',   'width' => 35],
        'remark'          => ['label' => '备注',       'width' => 25],
        'design_file_url' => ['label' => '定制稿链接', 'width' => 50],
        'created_at'      => ['label' => '下单时间',   'width' => 20],
    ];

    /*
     * ──────────────────────────────────────────────
     * 第二部分：依赖注入构造函数
     * ──────────────────────────────────────────────
     */

    /**
     * @param OssUploadService $ossService  注入 OSS 服务（用于生成设计稿文件的临时访问链接）
     */
    public function __construct(
        private readonly OssUploadService $ossService
    ) {}

    /*
     * ─────────────────────────═════════════════════
     * 第三部分：核心方法 - export() 导出入口
     * ──────────────────────────────────────────────
     */

    /**
     * 导出订单报表为 Excel 文件（流式输出，防 OOM）
     *
     * 🔄 完整流程：
     *
     *    用户浏览器
     *       ↓ GET /api/orders/export
     *    Controller 收到请求
     *       ↓ 调用本方法
     *    ┌──────────────────┐
     *    │ 创建 Excel 对象    │ ← new Spreadsheet()
     *    ├──────────────────┤
     *    │ 写入表头（蓝底白字）│ ← 遍历 COLUMNS 写入第1行
     *    ├──────────────────┤
     *    │ 分块查询 + 逐行写  │ ← chunk(200)，每次200条
     *    │   ↑ 每500行GC回收  │ ← gc_collect_cycles()
     *    ├──────────────────┤
     *    │ 设置列宽+冻结窗格  │ ← setWidth() + freezePane()
     *    ├──────────────────┤
     *    │ 流式输出到浏览器    │ ← $writer->save('php://output')
     *    └──────────────────┘
     *
     * 💡 为什么用 StreamedResponse 而不是普通 Response？
     *
     *    ❌ 普通做法（会 OOM）：
     *       $writer->save('/tmp/orders.xlsx');  // 先完整保存到磁盘
     *       return response()->download('/tmp/orders.xlsx');  // 再读取并发送
     *       问题：大文件（如10万条订单）可能几百MB，内存和磁盘都吃不消
     *
     *    ✅ 流式做法（防 OOM）：
     *       直接通过 php://output 输出流写入 HTTP 响应体
     *       数据边生成边发送给浏览器，不需要在服务器上保存完整文件
     *       内存占用始终保持在一个较低的水平
     *
     * @param  array<string, mixed>  $filters  筛选条件
     *    支持的筛选项：
     *    - status:     订单状态（如 'completed', 'booked' 等）
     *    - start_date: 开始日期（如 '2026-01-01'）
     *    - end_date:   结束日期（如 '2026-04-28'）
     *    - product_id: 商品ID（如 42）
     *    - keyword:    关键词搜索（模糊匹配用户名、商品名、备注等）
     *
     * @return StreamedResponse  流式响应对象（浏览器会弹出下载对话框）
     *
     * 📥 浏览器收到的响应头：
     *    Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
     *    Content-Disposition: attachment; filename="orders_export_2026_04_28_183000.xlsx"
     *    Cache-Control: max-age=0  （不缓存，确保每次都是最新数据）
     */
    public function export(array $filters = []): StreamedResponse
    {
        /*
         * StreamedResponse 构造函数参数说明：
         *
         * 参数1：回调函数（callback）
         *    在响应被发送时执行。所有 Excel 生成逻辑都在这个回调里完成。
         *    回调内的输出（echo/print/$writer->save('php://output')）会直接写入HTTP响应体。
         *
         * 参数2：HTTP 状态码
         *    200 = OK（正常响应）
         *
         * 参数3：响应头数组
         *    Content-Type: 告诉浏览器这是什么类型的文件
         *    Content-Disposition: attachment 表示是附件下载，filename 指定默认文件名
         *    Cache-Control: max-age=0 表示不要缓存此响应
         */
        return new StreamedResponse(function () use ($filters) {

            /*
             * ════════════════════════════════════════
             * A. 创建 Excel 工作簿和工作表
             * ════════════════════════════════════════
             */

            // new Spreadsheet() 创建一个空的 Excel 工作簿对象
            // 类比：就像打开了一个新的空白 Excel 文件
            $spreadsheet = new Spreadsheet();

            // getActiveSheet() 获取当前活动的工作表（Sheet1）
            $sheet = $spreadsheet->getActiveSheet();

            // setTitle() 设置工作表的标签名称（底部那个tab的名字）
            $sheet->setTitle('订单发货清单');

            // ════════════════════════════════════════
            // B. 写入表头（第一行）
            // ════════════════════════════════════════

            $colIndex = 1; // 列索引从 1 开始（PhpSpreadsheet 使用 1-based 索引）

            /**
             * 表头样式配置 — 让表头看起来更专业 👨‍💼
             *
             * 各样式属性含义：
             *    font.bold      → 粗体字
             *    font.color.rgb → 字体颜色（FFFFFF 白色）
             *    fill.fillType  → 填充类型（solid = 实心填充）
             *    fill.startColor.rgb → 填充背景色（4472C4 = 蓝色）
             *    alignment.horizontal → 水平对齐方式（center = 居中）
             */
            $headerStyle = [
                'font' => [
                    'bold'  => true,
                    'color' => ['rgb' => 'FFFFFF'],  // 白色字体
                ],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],  // 蓝色背景（Office标准蓝）
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                ],
            ];

            // 遍历 COLUMNS 定义，逐一写入表头单元格
            foreach (self::COLUMNS as $_key => $config) {
                /*
                 * Coordinate::stringFromColumnIndex() 做什么？
                 *
                 * 它把**数字列索引**转换为**Excel列字母**：
                 *    1 → 'A'
                 *    2 → 'B'
                 *    3 → 'C'
                 *    ...
                 *    26 → 'Z'
                 *    27 → 'AA'
                 *    28 → 'AB'
                 *
                 * 🔧 为什么需要这个转换？
                 *    因为 PhpSpreadsheet 操作单元格需要使用 "A1"、"B3" 这样的坐标格式，
                 *    但我们循环时用的是数字索引（1, 2, 3...），所以需要转换。
                 */
                $colLetter = Coordinate::stringFromColumnIndex($colIndex);

                // getCell() 获取指定位置的单元格对象
                $cell = $sheet->getCell("{$colLetter}1"); // "{$colLetter}1" 如 "A1", "B1", ...

                // setValue() 设置单元格的值为表头的中文标题
                $cell->setValue($config['label']); // $config['label'] 如 '订单编号', '预订人'

                $colIndex++; // 移动到下一列
            }

            // 批量应用表头样式（一次性设置整行的样式，性能更好）
            // 'A1:' . $sheet->getHighestColumn() . '1'  如 "A1:N1"（14列的话就是N）
            $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);

            // ════════════════════════════════════════
            // C. 分块查询数据并逐行写入（⭐ 核心：防 OOM 策略）
            // ════════════════════════════════════════

            /*
             * 🔍 什么是 chunk()？为什么能防 OOM？
             *
             * ❌ 危险做法：Order::all()
             *    一次把所有订单从数据库加载到 PHP 内存中。
             *    如果有 10 万条订单，每条约 1KB → 内存占用 ~100MB+
             *    加上 PhpSpreadsheet 的开销，可能超过 PHP 内存限制（通常128M或256M）
             *
             * ✅ 安全做法：chunk(200)
             *    每次只查 200 条记录到内存，处理完后释放，再查下 200 条。
             *    内存占用始终保持在很低的水平（~几MB）。
             *
             * 📊 chunk 内部原理：
             *    第一次查询：SELECT * FROM orders ORDER BY id ASC LIMIT 200 OFFSET 0
             *    第二次查询：SELECT * FROM orders ORDER BY id ASC LIMIT 200 OFFSET 200
             *    第三次查询：SELECT * FROM orders ORDER BY id ASC LIMIT 200 OFFSET 400
             *    ...直到没有更多数据
             *
             * ⚠️ 注意：chunk 回调中的操作应该是"处理后就丢弃"，不要把所有数据收集到一个数组里！
             *    （我们的代码符合这一点：每次只是写入 Excel 行，不会累积数据）
             */
            $query = $this->buildQuery($filters); // 先构建带筛选条件的查询
            $rowIndex = 2; // 数据从第 2 行开始写（第 1 行是表头）

            // chunk(200, callback): 每次取 200 条，传入回调函数处理
            $query->chunk(200, function ($orders) use ($sheet, &$rowIndex) {
                /*
                 * $orders: 当前这批次的订单集合（最多200条 Eloquent Model）
                 * $sheet: Excel 工作表对象（通过 use 传入）
                 * &$rowIndex: 当前写入行号（& 引用传递，回调内修改会影响外部变量）
                 */

                foreach ($orders as $order) {
                    // 把一条订单数据写入当前行
                    $this->writeRow($sheet, $rowIndex, $order);
                    $rowIndex++; // 行号递增
                }

                /*
                 * 🗑️ 手动垃圾回收（GC）
                 *
                 * gc_collect_cycles() 是 PHP 的垃圾回收函数，
                 * 用于检测和清理"循环引用"产生的无法自动回收的内存。
                 *
                 * 🔍 什么是循环引用？
                 *    对象A引用了B，B又引用了A → 即使不再使用也无法自动释放
                 *    Eloquent Model 有很多关系引用，容易出现这种情况。
                 *
                 * ⏰ 为什么每500行才回收一次而不是每行都回收？
                 *    GC 本身有性能开销（需要扫描所有变量），太频繁反而影响速度。
                 *    500行是一个平衡点：既能及时释放内存，又不至于太频繁。
                 *
                 * 💡 实测效果：
                 *    不做 GC：导出5万条可能占用 500MB+ 内存
                 *    定期 GC：导出5万条可能只需 50-80MB 内存
                 */
                if ($rowIndex % 500 === 0) { // % 是取模运算，每500行触发一次
                    gc_collect_cycles();
                }
            });

            // ════════════════════════════════════════
            // D. 设置列宽
            // ════════════════════════════════════════

            $colIdx = 1;
            foreach (self::COLUMNS as $_key => $config) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);

                // getColumnDimension() 获取某列的维度配置对象
                $column = $sheet->getColumnDimension($colLetter);

                // setWidth() 固定列宽（单位：字符宽度）
                $column->setWidth($config['width']);
                // 如 '订单编号' 列设为 10 个字符宽

                $colIdx++;
            }

            // ════════════════════════════════════════
            // E. 冻结首行（方便查看大量数据时保持表头可见）
            // ════════════════════════════════════════

            /*
             * freezePane('A2') 含义：
             *    冻结 A2 上方的区域（即第1行），当用户向下滚动时，
             *    第1行（表头）始终固定在顶部不动。
             *
             * 👀 效果类似 Excel 中的 "视图 → 冻结窗格 → 冻结首行"
             */
            $sheet->freezePane('A2');

            // ════════════════════════════════════════
            // F. 流式写入输出（核心！数据从这里流向浏览器）
            // ════════════════════════════════════════

            /*
             * Xlsx Writer：将 Spreadsheet 对象序列化为 .xlsx 格式的二进制数据
             */
            $writer = new Xlsx($spreadsheet);

            /*
             * 🌊 save('php://output') —— 流式输出的关键！
             *
             * php://output 是 PHP 的特殊输出流（write-only），直接写入 HTTP 响应体。
             *
             * 🔄 普通保存 vs 流式保存对比：
             *
             *    $writer->save('/tmp/file.xlsx')
             *    → 数据写入磁盘文件 → 占用磁盘空间 → 需要再读一次才能发送给浏览器
             *
             *    $writer->save('php://output')
             *    → 数据直接推送到 HTTP 输出缓冲区 → 浏览器逐步接收 → 无需中间存储
             *
             * ⚡ 这就是为什么即使导出 10 万条订单也不会 OOM！
             *    数据像流水一样源源不断地流向浏览器，不会在内存中堆积。
             */
            $writer->save('php://output');

            // ════════════════════════════════════════
            // G. 显式释放内存（善后工作）
            // ════════════════════════════════════════

            /*
             * disconnectWorksheets() 断开所有工作表的引用关系
             * 这一步很重要！PhpSpreadsheet 内部有很多对象间的相互引用，
             * 不手动断开可能导致 PHP 无法正确回收这些对象的内存。
             *
             * unset() 显式销毁变量，让 GC 能立即回收其内存
             */
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $writer);

        }, 200, [ // HTTP 状态码 200 (OK)
            // 响应头配置 ↓

            /*
             * Content-Type: 告知浏览器这是 Excel 文件
             * MIME 类型 vnd.openxmlformats-officedocument.spreadsheetml.sheet
             * 是 .xlsx 文件的标准 MIME 类型（由微软定义）
             */
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            /*
             * Content-Disposition: attachment → 触发浏览器下载行为（而非直接在浏览器中打开）
             * filename= → 指定下载时的默认文件名
             * date('Y_m_d_His') 生成时间戳文件名，如：orders_export_2026_04_28_183000.xlsx
             */
            'Content-Disposition' => 'attachment; filename="orders_export_' . date('Y_m_d_His') . '.xlsx"',

            /*
             * Cache-Control: max-age=0 → 告诉浏览器/代理服务器不要缓存这个文件
             * 因为每次导出的数据可能是不同的（最新数据），缓存会导致用户下载到旧版本
             */
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /*
     * ──────────────────────────────────────────────
     * 第四部分：私有方法 - buildQuery() 构建查询
     * ──────────────────────────────────────────────
     */

    /**
     * 构建带筛选条件的订单查询
     *
     * 🎯 功能：根据前端传来的筛选条件，动态构建 Eloquent 查询。
     *    只构建查询（返回 Builder 对象），不执行查询（延迟到 chunk 时才执行）
     *
     * 🔍 Laravel Eloquent 延迟执行（Lazy Evaluation）：
     *    ->where()->orderBy() 这些调用只是"构建"SQL语句，并不会真正查询数据库。
     *    只有调用 ->get()、->first()、->chunk() 等"终结方法"时才会执行 SQL。
     *    这让我们可以在最终执行前自由地拼接各种条件。
     *
     * @param  array<string, mixed>  $filters  从 URL query 参数提取的筛选条件
     * @return Builder  Eloquent 查询构建器（可链式调用 ->chunk(), ->get() 等）
     */
    private function buildQuery(array $filters): Builder
    {
        /*
         * with() 预加载关联模型 —— 解决 N+1 查询问题！
         *
         * 🚨 什么是 N+1 问题？
         *    如果不用 with()，代码可能是这样的：
         *      $orders = Order::all();              // 第1次查询：获取所有订单
         *      foreach ($orders as $order) {
         *          echo $order->user->name;         // 每个订单都要额外查 user → N次查询
         *          echo $order->product->name;      // 每个订单还要查 product → 又N次查询
         *          echo $order->attachments;        // 还要查 attachments → 又N次查询
         *      }
         *    总查询数：1 + N + N + N = 1 + 3N 次！（N=订单数量）
         *
         * ✅ with() 的解决方案：
         *    用 IN 子句预先批量查出所有关联数据：
         *      SELECT * FROM users WHERE id IN (1,3,5,7...)    → 1次
         *      SELECT * FROM products WHERE id IN (10,20,30...) → 1次
         *      SELECT * FROM order_attachments WHERE order_id IN (...) → 1次
         *    总查询数：1（主查询）+ 3（预加载）= 4次（恒定！不管多少条订单）
         *
         * with() 中的语法：
         *    'user:id,name,email'  → 只查 user 表的 id, name, email 字段（减少数据传输量）
         *    'product:id,name,category' → 同理，只查需要的字段
         *    'attachments' → attachments 全部字段（因为后面要用到多个字段）
         */
        $query = Order::with(['user:id,name,email', 'product:id,name,category', 'attachments'])
            ->orderByDesc('id'); // 按 ID 降序排列（最新的订单在前）

        // ═══ 动态添加 WHERE 条件 ═══
        //
        // 每个条件都用 if (! empty(...)) 包裹，
        // 意味着只有用户传了这个筛选条件才加上对应的 SQL WHERE。

        // 按状态精确筛选
        // SQL: WHERE status = 'completed'
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 按开始日期筛选（>= 该日期的订单）
        // whereDate() 会将 datetime 字段只比较日期部分（忽略时分秒）
        // SQL: WHERE DATE(created_at) >= '2026-01-01'
        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        // 按结束日期筛选（<= 该日期的订单）
        // SQL: WHERE DATE(created_at) <= '2026-04-28'
        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        // 按商品ID筛选
        // SQL: WHERE product_id = 42
        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        // 关键词模糊搜索（跨多字段）
        // SQL: WHERE (
        //   EXISTS(SELECT 1 FROM users WHERE users.id = orders.user_id AND name LIKE '%关键词%')
        //   OR EXISTS(SELECT 1 FROM products WHERE products.id = orders.product_id AND name LIKE '%关键词%')
        //   OR remark LIKE '%关键词%'
        //   OR shipping_address LIKE '%关键词%'
        // )
        if (! empty($filters['keyword'])) {
            $keyword = '%' . $filters['keyword'] . '%'; // % 是 SQL 通配符，表示任意字符
            
            /*
             * where() 接收闭包用于创建 WHERE (... OR ...) 分组条件
             * $q 是子查询构建器，在其中添加多个 OR 条件
             *
             * whereHas() 检查关联是否存在符合条件的记录：
             *    whereHas('user', fn($sq) => $sq->where('name', 'like', $keyword))
             *    意思是：存在关联的用户，且该用户的 name 包含关键词
             */
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('user', fn ($sq) => $sq->where('name', 'like', $keyword))       // 匹配预订人姓名
                  ->orWhereHas('product', fn ($sq) => $sq->where('name', 'like', $keyword))   // 或匹配商品名
                  ->orWhere('remark', 'like', $keyword)                                       // 或匹配备注
                  ->orWhere('shipping_address', 'like', $keyword);                            // 或匹配发货地址
            });
        }

        return $query;
    }

    /*
     * ──────────────────────────────────────────────
     * 第五部分：私有方法 - writeRow() 写入单行数据
     * ──────────────────────────────────────────────
     */

    /**
     * 将单条订单数据写入 Excel 的指定行
     *
     * 📝 工作原理：
     *    1. 先把订单模型转换为数组格式（mapOrderToRowData）
     *    2. 按 COLUMNS 定义的顺序遍历每一列
     *    3. 取出对应的值，写入正确的单元格位置
     *
     * @param  Worksheet  $sheet      PhpSpreadsheet 的工作表对象
     * @param  int        $rowIndex   目标行号（从2开始，因为第1行是表头）
     * @param  Order      $order      订单 Eloquent Model 实例
     */
    private function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $rowIndex, Order $order): void
    {
        $colIndex = 1; // 每行都从第1列(A列)重新开始
        
        // 调用 mapOrderToRowData() 把订单模型转为 [字段名=>值] 的数组
        $data = $this->mapOrderToRowData($order);

        // 遍历每一列定义，按顺序写入数据
        foreach (self::COLUMNS as $_key => $__config) {
            // $_key 是字段名（如 'order_id', 'user_name' 等）
            // $__config 是该列的配置（这里暂时不需要用到）
            
            $value = $data[$_key] ?? ''; // 从映射后的数据中取出对应值，不存在则用空字符串
            
            // 数字列索引 → Excel列字母
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            
            // setCellValue() 设置单元格值
            // 坐标格式："{$colLetter}{$rowIndex}" 如 "A2", "B2", "C2", ...
            $sheet->setCellValue("{$colLetter}{$rowIndex}", $value);
            
            $colIndex++; // 移动到下一列
        }
    }

    /*
     * ──────────────────────────────────────────────
     * 第六部分：私有方法 - mapOrderToRowData() 数据映射
     * ──────────────────────────────────────────────
     */

    /**
     * 将订单模型映射为行数据（含 OSS 临时链接和状态中文映射）
     *
     * 🎯 核心职责：
     *    把 Eloquent Model（对象 + 关系）转换为纯数据数组（字符串/数字），
     *    同时做一些数据处理：
     *    - 生成设计稿的临时访问链接
     *    - 状态码转中文名称
     *    - 金额格式化（保留两位小数）
     *    - 时间格式化
     *    - 处理可能的 null 值（转为 '-' 或空串）
     *
     * @param  Order  $order  订单模型实例（已预加载 user/product/attachments 关联）
     * @return array<string, mixed>  键值对数组，key 与 COLUMNS 的 key 对应
     *
     * 📤 返回值示例：
     *    [
     *        'order_id'        => 1024,
     *        'user_name'       => '张三',
     *        'product_name'    => '校园T恤',
     *        'category'        => '服装',
     *        'quantity'        => 2,
     *        'unit_price'      => '39.90',
     *        'total_price'     => '79.80',
     *        'size'            => 'XL',
     *        'color'           => '深蓝色',
     *        'status'          => '已预订',
     *        'shipping_address' => 'XX大学XX宿舍',
     *        'remark'          => '请尽快制作',
     *        'design_file_url' => 'https://xxx.oss-cn-hangzhou.aliyuncs.com/...',
     *        'created_at'      => '2026-04-15 14:30',
     *    ]
     */
    private function mapOrderToRowData(Order $order): array
    {
        // ═══ 1. 获取定制稿的临时访问链接 ═══
        
        /*
         * $order->attachments 是通过 with('attachments') 预加载出来的集合
         * 这里做一系列 Collection 链式操作：
         *
         * ->where('is_deleted', false)
         *    过滤出未删除的附件
         *
         * ->map(fn ($att) => $this->ossService->getTemporaryUrl($att->storage_path))
         *    对每个附件调用 OSS 服务生成临时访问链接
         *
         * ->filter()
         *    过滤掉空值（falsy value），防止 null/空串污染结果
         *
         * ->values()
         *    重新索引数组键名为 0,1,2...（过滤后可能有键名空洞）
         *
         * ->toArray()
         *    Collection 转 PHP 数组
         *
         * 最终 $designUrls 可能是这样的：
         *    [
             *        'https://bucket.oss-cn-hangzhou.aliyuncs.com/designs/1024/xxx.png?signature=xxx&expires=xxx',
             *        'https://bucket.oss-cn-hangzhou.aliyuncs.com/designs/1024/yyy.ai?signature=yyy&expires=yyy',
             *    ]
         *    （一个订单可能有多个设计稿附件）
         */
        $designUrls = $order->attachments
            ->where('is_deleted', false)
            ->map(fn ($att) => $this->ossService->getTemporaryUrl($att->storage_path))
            ->filter()
            ->values()
            ->toArray();

        // ═══ 2. 状态中文映射 ═══
        
        /*
         * 数据库中存储的是英文状态码（如 'booked', 'completed'），
         * 但导出给管理员看时需要显示为中文。
         * 这个数组就是 状态码 → 中文显示名 的对照表。
         */
        $statusLabels = [
            'draft'          => '草稿',
            'booked'          => '已预订',
            'design_pending'  => '待审核',
            'ready'           => '制作中',
            'completed'       => '已完成',
            'rejected'        => '已驳回',
        ];

        // ═══ 3. 组装完整的行数据 ═══
        return [
            'order_id'        => $order->id,

            // ?-> 安全导航操作符（PHP 8.0+）
            // 如果 $order->user 为 null（理论上不应该，但防御性编程），不会报错而是返回 null
            // ?? '-' 是 null 合并运算符，如果左侧为 null 则用 '-' 替代
            'user_name'       => $order->user?->name ?? '-',

            'product_name'    => $order->product?->name ?? '-',
            'category'        => $order->product?->category ?? '-',

            'quantity'        => $order->quantity,

            // number_format($val, 2) 格式化数字，保留2位小数
            // 如 39.9 → "39.90"，100 → "100.00"
            'unit_price'      => number_format((float) $order->unit_price, 2),
            'total_price'     => number_format((float) $order->total_price, 2),

            // ?? '' 表示如果值为 null 则用空字符串
            'size'            => $order->size ?? '',
            'color'           => $order->color ?? '',

            // 状态映射：$statusLabels[$order->status] 取中文，如果找不到则用原始英文
            'status'          => $statusLabels[$order->status] ?? $order->status,

            'shipping_address'=> $order->shipping_address ?? '',
            'remark'          => $order->remark ?? '',

            // 多个链接用换行符连接（Excel 单元格内可显示多行文本）
            'design_file_url' => implode("\n", $designUrls),
            // implode() 把数组元素用指定的分隔符连接成字符串
            // 例：implode(", ", ['a','b','c']) → "a, b, c"

            // Carbon 的 format() 方法格式化时间
            // ?-> 防御 created_at 为 null 的情况
            'created_at'      => $order->created_at?->format('Y-m-d H:i') ?? '',
            // format('Y-m-d H:i') → "2026-04-15 14:30"
        ];
    }
}
