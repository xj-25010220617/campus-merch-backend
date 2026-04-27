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
Route::post('/login', [AuthController::class, 'login']);
// 2. 受保护路由 (需要 Token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // 产品路由
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/my-orders', [OrderController::class, 'myOrders']);
    Route::post('/orders/{id}/complete', [OrderController::class, 'complete']);
    Route::post('/orders/{id}/design', [OrderDesignController::class, 'store']);

    Route::middleware('role:admin')->group(function () {
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::post('/products/import', [ProductController::class, 'import']);
        Route::put('/admin/orders/{id}/review', [OrderReviewController::class, 'review']);
        Route::get('/orders/export', [OrderExportController::class, 'export']);
        Route::get('/admin/stats', [StatsController::class, 'index']);
    });
});
