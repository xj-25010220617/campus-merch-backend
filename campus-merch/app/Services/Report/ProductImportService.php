<?php

/**
 * ============================================================================
 * 【接口3相关 - 导入部分】Excel 商品批量导入服务
 * ============================================================================
 *
 * 📌 功能概述：
 *    从用户上传的 Excel 文件中解析商品数据，进行逐行校验后批量写入数据库。
 *    核心特性：**部分成功**——合法数据入库，非法数据跳过并返回错误明细。
 *
 * 🔗 对接接口：
 *    POST /api/products/import → ProductController::import() → 本服务
 *
 * 💡 核心知识点：
 *    1. PhpSpreadsheet：PHP 处理 Excel 的主流库（读取/生成 .xlsx/.xls）
 *    2. 部分成功策略：不是"全部通过才入库"，而是"合法的入库，非法的报错"
 *    3. 行级校验：对 Excel 每一行独立校验，不因一行出错就拒绝整批数据
 *    4. updateOrCreate：Laravel 的 upsert 操作（存在则更新，不存在则创建）
 *    5. 表头动态映射：自动识别表头列名，不依赖固定列顺序
 *
 * 📖 相关概念：
 *    - IOFactory：PhpSpreadsheet 的工厂类，自动识别文件格式
 *    - chunk 分块：大数据量时分批处理，避免内存问题（本服务用行数上限替代）
 *    - upsert = update or insert，数据库的"更新或插入"操作
 */

namespace App\Services\Report;

use App\Enums\ProductCategory;              // 商品分类枚举（枚举值校验）
use App\Enums\ProductType;                  // 商品类型枚举（枚举值校验）
use App\Models\Product;                    // 商品 Model（对应 products 表）
use Illuminate\Http\UploadedFile;           // Laravel 上传文件封装
use Illuminate\Support\Facades\DB;          // 数据库事务门面（DB::transaction）
use PhpOffice\PhpSpreadsheet\IOFactory;     // PhpSpreadsheet 工厂类（自动识别Excel格式）
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException; // 读取异常类

class ProductImportService
{
    /*
     * ──────────────────────────────────────────────
     * 第一部分：配置常量 —— 模板定义与限制
     * ──────────────────────────────────────────────
     */

    /**
     * 导入模板列定义（首行表头）
     *
     * 📋 定义了 Excel 模板应该包含哪些列，以及每列的规则：
     *
     * | 列名(英文key) | 中文名称   | 是否必填 | 说明                     |
     * |---------------|------------|----------|--------------------------|
     * | name          | 商品名称   | ✅ 必填  | 商品的名称               |
     * | category      | 分类       | ✅ 必填  | 如：T恤、帆布袋、笔记本等 |
     * | type          | 类型       | ✅ 必填  | 如：标准款、定制款        |
     * | spec          | 规格说明   | ✅ 必填  | 如：XL、A4、50页         |
     * | price         | 单价       | ✅ 必填  | 单位：元，必须 > 0       |
     * | stock         | 库存数量   | ✅ 必填  | 必须 >= 0                |
     * | cover_url     | 封面图URL  | ✅ 必填  | 商品封面图片的网络地址     |
     * | custom_rule   | 定制规则   | ✅ 必填  | 定制说明/限制条件         |
     *
     * 🔑 为什么用关联数组而不用普通数组？
     *    key 是数据库字段名，value 是配置信息。这样可以通过 $config['required']
     *    快速判断某字段是否必填，$config['label'] 获取中文名称用于错误提示。
     */
    private const TEMPLATE_COLUMNS = [
        'name'        => ['required' => true,  'label' => '商品名称'],
        'category'    => ['required' => true,  'label' => '分类'],
        'type'        => ['required' => true,  'label' => '类型'],
        'spec'        => ['required' => true,  'label' => '规格说明'],
        'price'       => ['required' => true,  'label' => '单价'],
        'stock'       => ['required' => true,  'label' => '库存数量'],
        'cover_url'   => ['required' => true,  'label' => '封面图URL'],
        'custom_rule' => ['required' => true,  'label' => '定制规则'],
    ];

    /**
     * 最大允许导入行数（含表头）= 2001 行 = 2000 条数据 + 1 行表头
     *
     * 🛡️ 为什么要设上限？
     *    1. 防止恶意用户上传超大文件导致服务器 OOM
     *    2. 一次性处理太多数据可能导致 PHP 超时（默认30秒执行限制）
     *    3. 大量数据的导入应该走队列（Queue）异步处理，而不是同步接口
     *
     * ⚠️ 如果真的需要导入超过2000条怎么办？
     *    建议方案：分多次导入 / 改用队列任务异步处理
     */
    private const MAX_ROWS = 2001;

    /*
     * ──────────────────────────────────────────────
     * 第二部分：核心方法 - import() 导入入口
     * ──────────────────────────────────────────────
     */

    /**
     * 从 Excel 文件批量导入商品
     *
     * 🔄 完整处理流程：
     *
     *    ┌──────────────┐
     *    │ 1. 解析Excel │ ← 读取文件 → 二维数组
     *    └──────┬───────┘
     *           ↓
     *    ┌──────────────┐
     *    │ 2. 逐行校验  │ ← 每行独立检查必填项、格式
     *    ├──────┬───────┤
     *    │ 合法 │ 不合法│
     *    ↓      ↓
     *    ┌──────┐ ┌────┐
     *    │收集  │ │记录│
     *    │有效  │ │错误│
     *    │数据  │ │明细│
     *    └──┬───┘ └┬───┘
     *       └──┬──┘
     *          ↓
     *    ┌──────────────┐
     *    │ 3. 批量入库  │ ← 只把合法数据写入DB（事务保护）
     *    └──────┬───────┘
     *           ↓
     *    ┌──────────────┐
     *    │ 4. 返回结果  │ ← {success_count, fail_count, errors[]}
     *    └──────────────┘
     *
     * @param  UploadedFile  $file  用户上传的 Excel 文件
     * @return array{success_count: int, fail_count: int, errors: array}
     *         返回值结构：
     *           - success_count: 成功导入的商品数量
     *           - fail_count:    失败（被跳过）的行数
     *           - errors:        错误明细数组，每项包含 row/field/reason
     *
     * 📤 返回值示例：
     *    [
     *        'success_count' => 97,
     *        'fail_count'    => 3,
     *        'errors' => [
     *            ['row' => 5,  'field' => 'price', 'reason' => '价格必须为正数'],
     *            ['row' => 12, 'field' => 'name',  'reason' => '【商品名称】为必填项'],
     *            ['row' => 28, 'field' => 'stock', 'reason' => '库存不能为负数'],
     *        ],
     *    ]
     */
    public function import(UploadedFile $file): array
    {
        // ════════════════════════════════════════
        // 步骤1：解析 Excel 文件为二维数组
        // ════════════════════════════════════════

        /*
         * parseExcel() 返回的是什么样的数据？
         *
         * 假设 Excel 内容是：
         * ┌──────┬─────────┬──────────┬──────┬───────┬──────┐
         * │ name │ category│ type     │price │ stock │ ...  │
         * ├──────┼─────────┼──────────┼──────┼───────┼──────┤
         * │ T恤  │ 服装    │ 标准款   │39.9  │ 100   │ ...  │
         * │ 帆布袋│ 日用    │ 定制款   │25.0  │ 50    │ ...  │
         * └──────┴─────────┴──────────┴──────┴───────┴──────┘
         *
         * 解析后得到（$rows）：
         * [
         *     ['name'=>'T恤',   'category'=>'服装', 'type'=>'标准款', 'price'=>'39.9', 'stock'=>'100', ...],
         *     ['name'=>'帆布袋','category'=>'日用',  'type'=>'定制款', 'price'=>'25.0', 'stock'=>'50',  ...],
         * ]
         *
         * 注意：key 是表头中的英文字段名（通过 buildHeaderMap 映射而来）
         */
        $rows = $this->parseExcel($file);

        // ════════════════════════════════════════
        // 步骤2：逐行校验 —— 分类收集合法数据和错误
        // ════════════════════════════════════════

        $errors   = [];    // 存放所有行的错误信息
        $validData = [];    // 存放通过校验的有效数据
        $startRow = 2;      // Excel 中第1行是表头，从第2行开始才是数据

        /*
         * foreach 遍历每一行数据
         * $rowIndex 是从 0 开始的数组索引
         * $rowData 是这一行的数据（如 ['name'=>'T恤', ...]）
         *
         * $excelRow = $startRow + $rowIndex 把索引转换为真实的 Excel 行号
         * （因为用户看到的 Excel 行号是从1开始的，且第1行是表头）
         */
        foreach ($rows as $rowIndex => $rowData) {
            $excelRow = $startRow + $rowIndex;

            // 调用 validateRow() 校验这一行，返回错误数组（空=无错，非空=有错）
            $rowErrors = $this->validateRow($rowData, $excelRow);

            if (! empty($rowErrors)) {
                // 这一行有错误 → 收集错误信息，跳过这行（continue 进入下一次循环）
                $errors = array_merge($errors, $rowErrors);
                continue;
            }

            // 这行没问题 → 标准化数据格式后加入有效数据列表
            $validData[] = $this->normalizeProductData($rowData);
        }

        // ════════════════════════════════════════
        // 步骤3：批量写入数据库（事务保护）
        // ════════════════════════════════════════

        $insertedCount = 0; // 记录实际插入/更新的数量

        if (! empty($validData)) {
            // 只有当有有效数据时才开启事务（空数据没必要开事务）

            DB::transaction(function () use ($validData, &$insertedCount) {
                /*
                 * 遍历所有有效数据，逐一处理
                 *
                 * 💡 这里为什么不用 insert() 批量插入？
                 *    因为我们用的是 updateOrCreate() —— "存在则更新，不存在则创建"
                 *    这是 **upsert**（update+insert）语义，不能简单批量插入。
                 *
                 * 🔄 updateOrCreate() 工作原理：
                 *    第一个参数（属性数组）：用来查找匹配的记录（WHERE 条件）
                 *    第二个参数（值数组）：   找到则更新这些字段，找不到则创建新记录
                 *
                 *    例：按 name + category 匹配
                 *        如果已有 name='T恤' AND category='服装' 的记录 → 更新 price/stock 等
                 *        如果没有 → 插入一条新记录
                 *
                 * ⚠️ 这种方式适合中小批量数据（几百到几千条）。
                 *    超大批量应使用 DB::table()->upsert() 或 SQL 的 INSERT ... ON DUPLICATE KEY UPDATE
                 */
                foreach ($validData as $data) {
                    Product::updateOrCreate(
                        [
                            // 查找条件：按 商品名+分类 组合判断是否重复
                            'name'     => $data['name'],
                            'category' => $data['category'],
                        ],
                        // 要写入的数据（合并额外字段）
                        array_merge($data, [
                            'status' => 'active',  // 新商品默认状态：上架(active)
                        ])
                    );
                    $insertedCount++; // & 引用传递，让外部的变量也能递增
                }
            });
        }

        // ════════════════════════════════════════
        // 步骤4：返回汇总结果
        // ════════════════════════════════════════
        return [
            'success_count' => $insertedCount,  // 成功条数
            'fail_count'    => count($errors),  // 失败条数
            'errors'        => $errors,         // 错误明细
        ];
    }

    /*
     * ──────────────────────────────────────────────
     * 第三部分：私有方法 - parseExcel() Excel解析
     * ──────────────────────────────────────────────
     */

    /**
     * 解析 Excel 文件为二维数组
     *
     * 📖 使用 PhpSpreadsheet 库来读取 Excel 文件。
     *    PhpSpreadsheet 是 PHP 生态中最流行的 Excel 处理库，
     *    支持 .xlsx (Excel 2007+)、.xls (Excel 97-2003)、.csv 等格式。
     *
     * 🔧 IOFactory::load() 做了什么？
     *    它是一个工厂方法，会自动检测文件的实际格式，
     *    然后选择合适的 Reader 来解析：
     *    - .xlsx → Xlsx Reader
     *    - .xls  → Xls Reader (Excel5)
     *    - .csv  → Csv Reader
     *
     * @param  UploadedFile  $file  上传的文件对象
     * @return array<int, array<string, mixed>>  二维数组，每个元素是一行数据（key为字段名）
     *
     * @throws \InvalidArgumentException 当文件无法解析或超出行数限制时抛出
     */
    private function parseExcel(UploadedFile $file): array
    {
        try {
            // ════════════════════════════════════════
            // A. 加载 Excel 文件
            // ════════════════════════════════════════

            /*
             * $file->getRealPath() 获取上传文件的临时绝对路径
             * 类似：C:\xampp\tmp\phpABC.tmp
             *
             * IOFactory::load() 会读取这个临时文件并返回 Spreadsheet 对象
             * Spreadsheet 对象代表整个工作簿（可以包含多个 Sheet）
             */
            $spreadsheet = IOFactory::load($file->getRealPath());

            // 获取活动工作表（当前正在查看的那个 sheet）
            $sheet = $spreadsheet->getActiveSheet();

            // getHighestRow() 返回有内容的最大行号（如 101 表示有101行数据）
            $highestRow = $sheet->getHighestRow();

            // ════════════════════════════════════════
            // B. 行数上限校验
            // ════════════════════════════════════════

            if ($highestRow > self::MAX_ROWS) {
                throw new \InvalidArgumentException(
                    "导入文件行数({$highestRow})超过限制(" . (self::MAX_ROWS - 1) . "条数据)"
                );
            }

            // ════════════════════════════════════════
            // C. 读取并映射表头
            // ════════════════════════════════════════

            /*
             * rangeToArray() 方法：
             *    将指定范围的单元格转为二维数组
             *
             *    参数说明：
             *    - "A1:H1" → 读取范围（从 A1 到 H1，即第一行所有列）
             *    - null    → null 值如何处理（null 表示保持原样）
             *    - true   → 计算公式？（true 则计算公式的值）
             *    - false  → 格式化数据显示？（false 返回原始值而非格式化后的）
             *
             *    返回示例：
             *    [['name', 'category', 'type', 'spec', 'price', 'stock', 'cover_url', 'custom_rule']]
             *    注意：这是一个二维数组（只有一行），所以用 [0] 取出第一行
             */
            $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, false)[0] ?? [];

            /*
             * buildHeaderMap() 把表头文字映射为数据库字段名
             *
             * 例：输入 ['商品名称', '分类', '类型', ...]
             *     输出 [1=>'name', 2=>'category', 3=>'type', ...]
             *     （key 是列索引，value 是对应的数据库字段名）
             */
            $headerMap = $this->buildHeaderMap($headerRow);

            // 如果映射结果全为 null，说明表头完全无法识别
            if (empty(array_filter($headerMap))) {
                throw new \InvalidArgumentException(
                    'Excel 表头为空或无法识别。请使用标准模板格式：name, category, type, spec, price, stock, cover_url, custom_rule'
                );
            }

            // ════════════════════════════════════════
            // D. 逐行读取数据并映射字段名
            // ════════════════════════════════════════

            $dataRows = [];
            
            // 从第2行开始遍历（第1行是表头），直到最后一行
            for ($row = 2; $row <= $highestRow; $row++) {
                /*
                 * 读取当前行的所有单元格
                 * 动态构建范围字符串："A{$row}:H{$row}" 例如 "A3:H3"
                 */
                $rowData = $sheet->rangeToArray(
                    "A{$row}:" . $sheet->getHighestColumn() . "{$row}",
                    null,
                    true,
                    false
                )[0] ?? [];

                // 跳过完全空白的行（用户可能留了一些空行）
                // array_filter() 默认会过滤掉 falsy 值（null, '', 0, false）
                // fn ($v) => $v !== null && $v !== '' 自定义过滤条件：只保留非空非null的值
                if (! array_filter($rowData, fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }

                // 按 headerMap 映射：把 "第0列的值" → "name字段的值"
                $mappedRow = [];
                foreach ($headerMap as $colIndex => $columnName) {
                    if ($columnName !== null) {
                        // $colIndex 是列的位置（0,1,2...），$columnName 是字段名
                        // $rowData[$colIndex] 取出该位置的值
                        $mappedRow[$columnName] = $rowData[$colIndex] ?? null;
                        // ?? null 表示如果该位置不存在则用 null 作为默认值
                    }
                }
                $dataRows[] = $mappedRow;
            }

            return $dataRows;
            // 最终返回类似：
            // [
            //     ['name'=>'T恤', 'category'=>'服装', 'type'=>'标准款', 'price'=>'39.9', ...],
            //     ['name'=>'帆布袋', 'category'=>'日用', 'type'=>'定制款', 'price'=>'25.0', ...],
            // ]

        } catch (ReaderException $e) {
            // 文件格式不被支持（不是有效的 Excel 文件）
            throw new \InvalidArgumentException('无法解析该 Excel 文件，请确保文件格式正确（支持 .xlsx, .xls, .csv）。');
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            // 其他读取错误（文件损坏、权限问题等）
            throw new \InvalidArgumentException('读取 Excel 文件时出错：' . $e->getMessage());
        }
    }

    /*
     * ──────────────────────────────────────────────
     * 第四部分：私有方法 - buildHeaderMap() 表头映射
     * ──────────────────────────────────────────────
     */

    /**
     * 构建列名映射（从表头行到标准字段名）
     *
     * 🎯 解决的问题：
     *    用户上传的 Excel 表头可能是中文也可能是英文，
     *    列顺序也可能不一样。我们需要建立"列位置→字段名"的映射关系。
     *
     * 📝 工作原理：
     *    遍历表头的每一个单元格，检查它的值是否在已知的字段名列表中。
     *    如果是，记录映射；如果不是，标记为 null（该列会被忽略）。
     *
     * @param  array<int, string|null>  $headerRow  表头行的原始数据（一维数组）
     * @return array<int, string|null>  映射结果（key=列索引，value=字段名或null）
     *
     * 💡 示例：
     *    输入：['商品名称', '分类', '单价', '备注', '库存数量']
     *    输出：[0=>'name', 1=>'category', 2=>null, 3=>null, 4=>'stock']
     *           ↑ 单价和备注不在标准字段名列表中，所以映射为 null（忽略这两列）
     *
     * ⚠️ 注意：这里要求表头使用**英文字段名**！
     *    如果需要支持中文表头，需要额外的中英文对照映射表。
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        
        // array_keys(self::TEMPLATE_COLUMNS) 提取所有已知字段名的 key
        // 得到：['name', 'category', 'type', 'spec', 'price', 'stock', 'cover_url', 'custom_rule']
        $knownColumns = array_keys(self::TEMPLATE_COLUMNS);

        foreach ($headerRow as $index => $cellValue) {
            $trimmed = trim((string) $cellValue); // 去除首尾空白
            
            // in_array 检查这个表头是否是已知字段名之一
            if (in_array($trimmed, $knownColumns, true)) {
                $map[$index] = $trimmed;  // 是 → 记录映射
            } else {
                $map[$index] = null;      // 不是 → 标记为 null（该列将被忽略）
            }
        }

        return $map;
    }

    /*
     * ──────────────────────────────────────────────
     * 第五部分：私有方法 - validateRow() 行级校验
     * ──────────────────────────────────────────────
     */

    /**
     * 校验单行数据
     *
     * 🎯 校验策略：
     *    - 必填项为空 → 报错
     *    - 价格 <= 0 → 报错（价格必须是正数）
     *    - 库存 < 0  → 报错（库存不能是负数）
     *    - 一个字段可能产生多个错误？不，目前每个字段最多1个错误
     *
     * @param  array  $row      一行原始数据（key为字段名）
     * @param  int    $excelRow Excel 中的真实行号（用于错误定位，方便用户查找）
     * @return array<int, array{row: int, field: string, reason: string}>
     *         返回错误数组（空数组表示无错）
     *
     * 📤 返回值示例（有错）：
     *    [
     *        ['row'=>3, 'field'=>'price', 'reason'=>'价格必须为正数'],
     *        ['row'=>3, 'field'=>'stock', 'reason'=>'库存不能为负数'],
     *    ]
     *
     * 📤 返回值示例（无错）：[]
     */
    private function validateRow(array $row, int $excelRow): array
    {
        $errors = [];

        /*
         * 遍历 TEMPLATE_COLUMNS 中定义的所有字段
         * $field = 字段名（如 'name', 'price' 等）
         * $config = 该字段的配置（如 ['required'=>true, 'label'=>'商品名称']）
         */
        foreach (self::TEMPLATE_COLUMNS as $field => $config) {
            // 获取该字段的值，如果不存在则默认为空字符串
            // trim() 去除首尾空白（防止 "  T恤  " 这样的值绕过空值检查）
            $value = trim((string) ($row[$field] ?? ''));

            // ═══ 校验1：必填项检查 ═══
            if ($config['required'] && $value === '') {
                $errors[] = [
                    'row'    => $excelRow,              // 方便用户在 Excel 中定位
                    'field'  => $field,                  // 哪个字段有问题
                    'reason' => "【{$config['label']}】为必填项", // 友好的中文提示
                ];
                continue; // 跳过后续校验（已经知道这项有问题了）
            }

            // 非必填字段且值为空 → 直接跳过，不需要进一步校验
            if (! $config['required'] && $value === '') {
                continue;
            }

            // ═══ 校验2：特定字段的格式验证 ═══
            switch ($field) {

                case 'price':
                    /*
                     * FILTER_VALIDATE_FLOAT 是 PHP 内置过滤器
                     * 用于验证值是否是有效的浮点数
                     *
                     * 返回值：
                     *    成功 → 浮点数值（如 39.9）
                     *    失败 → false（如 "abc"、""--空字符串前面已被拦截）
                     */
                    $priceVal = filter_var($value, FILTER_VALIDATE_FLOAT);
                    
                    if ($priceVal === false || $priceVal <= 0) {
                        $errors[] = [
                            'row'    => $excelRow,
                            'field'  => $field,
                            'reason' => '价格必须为正数',
                        ];
                    }
                    break;

                case 'stock':
                    /*
                     * FILTER_VALIDATE_INT 验证整数
                     * 允许 0（库存为0 = 缺货），不允许负数
                     */
                    $stockVal = filter_var($value, FILTER_VALIDATE_INT);
                    
                    if ($stockVal === false || $stockVal < 0) {
                        $errors[] = [
                            'row'    => $excelRow,
                            'field'  => $field,
                            'reason' => '库存不能为负数',
                        ];
                    }
                    break;

                case 'category':
                    /*
                     * 枚举值校验：分类必须是 ProductCategory 中定义的合法值
                     *
                     * ProductCategory::cases() 返回所有枚举实例
                     * array_column(..., 'value') 提取所有合法的字符串值
                     * 如：['clothing', 'daily', 'creative', 'stationery', 'accessory']
                     */
                    $validCategories = array_column(ProductCategory::cases(), 'value');
                    if (! in_array($value, $validCategories, true)) {
                        $errors[] = [
                            'row'    => $excelRow,
                            'field'  => $field,
                            'reason' => '分类值非法，允许值：' . implode(', ', $validCategories),
                        ];
                    }
                    break;

                case 'type':
                    /*
                     * 枚举值校验：类型必须是 ProductType 中定义的合法值
                     * 允许值：standard（标准款）、custom（定制款）
                     */
                    $validTypes = array_column(ProductType::cases(), 'value');
                    if (! in_array($value, $validTypes, true)) {
                        $errors[] = [
                            'row'    => $excelRow,
                            'field'  => $field,
                            'reason' => '类型值非法，允许值：' . implode(', ', $validTypes),
                        ];
                    }
                    break;

                // 其他字段暂无特殊校验规则...
                // 未来可在此扩展：cover_url URL格式校验等
            }
        }

        return $errors;
    }

    /*
     * ──────────────────────────────────────────────
     * 第六部分：私有方法 - normalizeProductData() 数据标准化
     * ─────────────────────────════════════════════
     */

    /**
     * 标准化商品数据，转换为数据库可接受的格式
     *
     * 🎯 为什么需要"标准化"？
     *    Excel 中读出的数据都是字符串（string），
     *    但数据库中某些字段需要特定的类型（int/float/null）。
     *    这个方法负责类型转换和默认值填充。
     *
     * 📝 转换规则：
     *    - 所有文本字段：trim() 去空白
     *    - 可选字段为空 → 存 null（而不是空字符串 ''）
     *    - price 字符串 → float 浮点数
     *    - stock 字符串 → int 整数
     *    - reserved_stock/sold_stock → 默认 0
     *    - version → 默认 0（乐观锁版本号，初始为0）
     *
     * @param  array  $row  一行原始数据
     * @return array  标准化后的数据（可直接传入 Product::create() 或 updateOrCreate()）
     *
     * 💡 示例：
     *    输入：['name'=>' T恤 ', 'category'=>'服装', 'price'=>'39.90', 'stock'=>'100', 'spec'=>'']
     *    输出：['name'=>'T恤', 'category'=>'服装', 'price'=>39.9, 'stock'=>100, 'spec'=>null, ...]
     */
    private function normalizeProductData(array $row): array
    {
        return [
            'name'          => trim((string) ($row['name'] ?? '')),
            'category'      => trim((string) ($row['category'] ?? '')),
            'type'          => trim((string) ($row['type'] ?? '')),
            
            // spec 为空 → 存 null（表示"未设置"比存空字符串更规范）
            'spec'          => ! empty(trim((string) ($row['spec'] ?? '')))
                                ? trim((string) $row['spec']) : null,

            // 强制类型转换：字符串 → 数值
            'price'         => (float) ($row['price'] ?? 0),  // '39.9' → 39.9
            'stock'         => (int) ($row['stock'] ?? 0),     // '100'  → 100

            // 新商品的初始值
            'reserved_stock'=> 0,   // 预占库存 = 0
            'sold_stock'    => 0,   // 已售库存 = 0

            // 可选字段同上，空则存 null
            'cover_url'     => ! empty(trim((string) ($row['cover_url'] ?? '')))
                                ? trim((string) $row['cover_url']) : null,
            'custom_rule'   => ! empty(trim((string) ($row['custom_rule'] ?? '')))
                                ? trim((string) $row['custom_rule']) : null,

            /*
             * version 字段 —— 乐观锁版本号
             *
             * 🔒 什么是乐观锁？
             *    一种并发控制策略，假设冲突很少发生。
             *    每次更新时检查 version 是否变化：
             *      UPDATE products SET ..., version = version + 1 WHERE id = ? AND version = 0
             *    如果 version 已经被别人改过了（!= 0），说明有人同时修改了这条记录，
             *    UPDATE 影响行数为 0，就知道发生了并发冲突。
             *
             * 🆚 对比悲观锁（SELECT ... FOR UPDATE）：
             *    乐观锁：性能好，适合读多写少场景
             *    悲观锁：更安全，但会阻塞其他事务
             */
            'version'       => 0,   // 初始版本号
        ];
    }
}
