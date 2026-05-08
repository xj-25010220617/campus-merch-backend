<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;      // 1. 引入用户模型
use App\Models\Product;  // 2. 引入商品模型
use App\Models\Order;    // 3. 引入订单模型
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    /**
     * GET /api/admin/stats
     * 获取后台首页统计数据
     */
    public function index(): JsonResponse
    {
        // 4. 编写统计逻辑

        // 用户统计
        $userCount = User::count();

        // 商品统计
        $productCount = Product::count();

        // 订单统计 (示例：统计所有订单数)
        $orderCount = Order::count();

        // 销售额统计 (示例：统计已支付订单的总金额)
        // 假设 Order 模型中有一个 status 字段，1 代表已支付/已完成
        $totalSales = Order::where('status', 1)->sum('total_price');

        // 待审核订单数 (示例：假设 status 0 代表待审核)
        $pendingReviewCount = Order::where('status', 0)->count();

        // 5. 返回数据
        return response()->json([
            'code' => 0,
            'message' => '获取统计成功',
            'data' => [
                'total_users' => $userCount,
                'total_products' => $productCount,
                'total_orders' => $orderCount,
                'total_sales' => $totalSales,
                'pending_review' => $pendingReviewCount,
            ]
        ]);
    }
}
