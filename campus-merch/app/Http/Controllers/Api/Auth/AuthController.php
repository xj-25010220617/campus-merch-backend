<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->authService->register($request->validated()),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->authService->login($request->validated()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout(
            $request->user(),
            $request->user()?->currentAccessToken()?->id,
        );

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->authService->me($request->user()),
        ]);
    }
}
