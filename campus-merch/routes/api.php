<?php

use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\File\OrderDesignController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\Order\OrderReviewController;
use App\Http\Controllers\Api\Product\ProductController;
use App\Http\Controllers\Api\Report\OrderExportController;
use Illuminate\Support\Facades\Route;
// 1. 公共路由 (无需 Token)
Route::post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:5,1')->post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // 产品路由
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/my-orders', [OrderController::class, 'myOrders']);
    Route::post('/orders/{order}/complete', [OrderController::class, 'complete']);
    Route::post('/orders/{order}/design', [OrderDesignController::class, 'store']);

    // 管理员路由（需 admin 角色）
    Route::middleware('role:admin')->group(function () {
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::post('/products/import', [ProductController::class, 'import']);
        Route::put('/admin/orders/{order}/review', [OrderReviewController::class, 'review']);
        Route::get('/admin/stats', [StatsController::class, 'index']);

        // ⚠️ /orders/export 必须在 /orders/{order} 之前，否则动态路由会吞掉 "export"
        Route::get('/orders/export', [OrderExportController::class, 'export']);
    });

    // ⚠️ 动态路由放最后，避免吞掉固定路径（如 export）
    Route::get('/orders/{order}', [OrderController::class, 'show']);
});
