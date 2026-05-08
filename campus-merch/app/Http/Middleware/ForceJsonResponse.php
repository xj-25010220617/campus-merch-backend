<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // 强制设置 Accept 请求头为 JSON
        $request->headers->set('Accept', 'application/json');
        
        $response = $next($request);
        
        // 确保响应是 JSON 格式
        if (!$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'application/json');
        }
        
        return $response;
    }
}