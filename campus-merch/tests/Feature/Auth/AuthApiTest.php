<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'phone' => '13812345678',
        ]);

        $response->assertCreated()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.user.email', 'new@example.com')
            ->assertJsonPath('data.user.role', UserRole::USER->value);
    }

    public function test_register_cannot_escalate_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New Admin',
            'email' => 'admin2@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => UserRole::ADMIN->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.role', UserRole::USER->value);
    }

    public function test_user_can_login_and_get_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'Password123',
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123',
        ]);

        $token = $loginResponse->json('data.token');

        $loginResponse->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.user.email', $user->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'blocked@example.com',
            'password' => 'Password123',
            'status' => 'disabled',
        ]);

        $this->postJson('/api/login', [
            'email' => 'blocked@example.com',
            'password' => 'Password123',
        ])->assertUnauthorized();
    }
}
