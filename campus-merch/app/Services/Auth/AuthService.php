<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(array $payload): array
    {
        $user = User::create([
            'name' => $payload['name'],
            'email' => strtolower($payload['email']),
            'password' => $payload['password'],
            'phone' => $payload['phone'] ?? null,
            'role' => UserRole::USER,
            'status' => 'active',
        ]);

        return $this->buildAuthPayload($user, 'register-token');
    }

    public function login(array $payload): array
    {
        $user = User::query()
            ->where('email', strtolower($payload['email']))
            ->first();

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw new AuthenticationException('This account is disabled.');
        }

        return $this->buildAuthPayload($user, 'login-token');
    }

    public function logout(User $user, ?string $tokenId): void
    {
        if ($tokenId) {
            $user->tokens()->whereKey($tokenId)->delete();
            return;
        }

        $user->tokens()->delete();
    }

    public function me(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role?->value ?? $user->role,
            'status' => $user->status,
            'created_at' => $user->created_at,
        ];
    }

    private function buildAuthPayload(User $user, string $tokenName): array
    {
        $user->tokens()->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->me($user),
        ];
    }
}
