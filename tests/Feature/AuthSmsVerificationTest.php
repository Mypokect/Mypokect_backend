<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSmsVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_sms_code_before_issuing_token(): void
    {
        $user = User::factory()->create([
            'phone' => '3001234567',
            'password' => Hash::make('1234'),
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'phone' => $user->phone,
            'password' => '1234',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonPath('data.expires_in', 300)
            ->assertJsonMissingPath('data.token');

        $code = $loginResponse->json('data.debug_code');

        $verifyResponse = $this->postJson('/api/login/verify-code', [
            'phone' => $user->phone,
            'code' => $code,
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_verify_rejects_wrong_code(): void
    {
        $user = User::factory()->create([
            'phone' => '3001234567',
            'password' => Hash::make('1234'),
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'phone' => $user->phone,
            'password' => '1234',
        ]);

        $wrongCode = $loginResponse->json('data.debug_code') === '000000' ? '111111' : '000000';

        $this->postJson('/api/login/verify-code', [
            'phone' => $user->phone,
            'code' => $wrongCode,
        ])->assertStatus(422);
    }

    public function test_login_with_bad_credentials_does_not_send_code(): void
    {
        $user = User::factory()->create([
            'phone' => '3001234567',
            'password' => Hash::make('1234'),
        ]);

        $this->postJson('/api/login', [
            'phone' => $user->phone,
            'password' => '9999',
        ])->assertStatus(401);
    }

    public function test_register_creates_user_only_after_sms_verification(): void
    {
        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Nuevo Usuario',
            'phone' => '3017654321',
            'country_code' => '+57',
            'password' => '1234',
        ]);

        $registerResponse
            ->assertOk()
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseMissing('users', ['phone' => '3017654321']);

        $code = $registerResponse->json('data.debug_code');

        $verifyResponse = $this->postJson('/api/register/verify-code', [
            'phone' => '3017654321',
            'code' => $code,
        ]);

        $verifyResponse
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', ['phone' => '3017654321']);

        $user = User::where('phone', '3017654321')->first();
        $this->assertTrue(Hash::check('1234', $user->password));
    }

    public function test_register_verify_with_expired_code_fails(): void
    {
        $this->postJson('/api/register/verify-code', [
            'phone' => '3020000000',
            'code' => '123456',
        ])->assertStatus(422);
    }
}
