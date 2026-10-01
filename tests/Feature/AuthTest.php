<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_login_me_and_token_revocation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ADA@example.com', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123']);
        $response->assertCreated()->assertJsonPath('data.user.email', 'ada@example.com')->assertJsonMissingPath('data.user.password');
        $this->assertTrue(Hash::check('SecurePassword123', User::first()->password));
        $this->assertNotSame('SecurePassword123', User::first()->password);
        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.name', 'Ada');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'SecurePassword123'])->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at']]);
    }

    public function test_invalid_credentials_and_protected_endpoint(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->getJson('/api/v1/monitors')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.com', 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_registration_validation_and_public_demo_switch(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'invalid', 'password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        config(['sentinel.registration_enabled' => false]);
        $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123'])->assertForbidden();
    }

    public function test_auth_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', [])->assertStatus(429);
    }
}
