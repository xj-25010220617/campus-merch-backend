<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse([
                'code' => 401,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        if (! in_array($user->role->value ?? $user->role, $roles, true)) {
            return new JsonResponse([
                'code' => 403,
                'message' => 'Forbidden.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
