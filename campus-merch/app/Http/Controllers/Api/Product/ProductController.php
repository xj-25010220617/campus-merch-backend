<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Product; // 引入模型
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // 别忘了引入 Request
use Illuminate\Support\Facades\Validator; // 引入验证器
use Illuminate\Support\Facades\Storage; // 引入存储门面
use PhpOffice\PhpSpreadsheet\IOFactory; // 引入 Excel 处理库

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 1. 构建查询构造器
        $query = Product::query();

        // 2. 条件筛选 (根据需求文档 PDM)
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('min_price') && $request->filled('max_price')) {
            $query->whereBetween('price', [$request->min_price, $request->max_price]);
        }

        // 3. 库存预警逻辑 (核心需求)
        // 我们需要筛选出 (总库存 - 预扣库存) < 10 的商品打上预警标签
        // 注意：这里为了演示，我们在查询后处理；如果是大数据量，建议在数据库层计算或用视图
        $products = $query->get();

        // 4. 数据转换：添加预警字段
        $data = $products->map(function ($product) {
            // 计算可用库存 = 总库存 - 预扣库存
            $availableStock = $product->stock - $product->reserved_stock;

            // 添加预警字段
            $product->is_stock_low = $availableStock < 10;

            // 将模型转为数组以便返回
            $item = $product->toArray();
            $item['available_stock'] = $availableStock; // 返回可用库存给前端
            return $item;
        });

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $data
        ]);
    }

    public function show(int $id): JsonResponse
    {
        // 使用 findOrFail 处理异常
        $product = Product::withCount('orders') // 假设有一个 orders 关联
        ->find($id);

        if (!$product) {
            return response()->json(['code' => 404, 'message' => '商品不存在']);
        }

        // 附件聚合 (模拟设计稿示例)
        $attachments = [
            'design_examples' => [
                ['url' => '/example/design1.jpg', 'title' => '往期设计参考'],
                ['url' => '/example/design2.jpg', 'title' => '往期设计参考2'],
            ],
            'manual' => '/manual.pdf' // 说明书
        ];

        // 合并数据
        $result = $product->toArray();
        $result['attachments'] = $attachments;

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $result
        ]);
    }

    public function update(Request $request,int $id): JsonResponse
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
        // 在 Model 中我们已经写了 updateWithLock 方法
        // 这里假设直接操作数据库
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
            // 没有行被更新，说明版本号不匹配 (数据被其他人修改了)
            return response()->json([
                'code' => 409,
                'message' => '数据版本冲突，请刷新页面重试'
            ]);
        }

        return response()->json(['code' => 0, 'message' => '更新成功']);
    }

    public function import(Request $request): JsonResponse
    {
        // 1. 验证上传文件
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx,xls,csv|max:2048' // 2MB限制
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => '文件格式错误']);
        }

        $file = $request->file('file');

        try {
            // 2. 读取 Excel
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // 跳过标题行
            array_shift($rows);

            $importData = [];
            foreach ($rows as $row) {
                // 假设 Excel 列顺序：商品名, 价格, 库存, 分类
                if (empty($row[0])) continue;

                $importData[] = [
                    'name' => $row[0],
                    'price' => $row[1],
                    'stock' => $row[2],
                    'category' => $row[3],
                    'status' => 'active',
                    'version' => 1, // 新增商品版本初始化为1
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            // 3. 批量插入
            // 注意：如果数据量极大，建议分批插入 (chunk)
            Product::insert($importData);

            return response()->json([
                'code' => 0,
                'message' => '导入成功',
                'data' => ['count' => count($importData)]
            ]);

        } catch (\Exception $e) {
            return response()->json(['code' => 500, 'message' => '导入失败：' . $e->getMessage()]);
        }
    }
}
