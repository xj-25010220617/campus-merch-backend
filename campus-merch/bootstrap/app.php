<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        // API 路由跳过 CSRF 验证
        $middleware->validateCsrfTokens(except: [
            '/api/*',
        ]);

        // 认证失败时返回 JSON 401（不重定向到登录页）
        $middleware->redirectGuestsTo(function () {
            return null;
        });

        // 强制 API 请求返回 JSON
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 401: 未认证 → 返回 JSON（中文不转义）
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            return response()->json([
                'code' => 401,
                'message' => '未认证，请先登录',
                'data' => null,
            ], 401, [], JSON_UNESCAPED_UNICODE);
        });

        // 业务逻辑异常 → API 路由强制返回 JSON
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                $code = ($e instanceof \Illuminate\Validation\ValidationException) ? 422
                    : (($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) ? 404 : (($e instanceof \InvalidArgumentException) ? 422 : 500));
                return response()->json([
                    'code' => $code,
                    'message' => $code === 404 ? '请求的资源不存在' : ($e->getMessage() ?: '服务器内部错误'),
                    'data' => null,
                ], $code, [], JSON_UNESCAPED_UNICODE);
            }
        });
    })->create();
