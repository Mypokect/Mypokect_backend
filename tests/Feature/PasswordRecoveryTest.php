<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requests_verifies_and_resets_password(): void
    {
        $user = User::factory()->create([
            'phone' => '3001234567',
            'password' => Hash::make('1234'),
        ]);

        $requestResponse = $this->postJson('/api/password-recovery/request-code', [
            'phone' => $user->phone,
        ]);

        $requestResponse
            ->assertOk()
            ->assertJsonPath('data.phone', $user->phone)
            ->assertJsonPath('data.expires_in', 30);

        $code = $requestResponse->json('data.debug_code');

        $verifyResponse = $this->postJson('/api/password-recovery/verify-code', [
            'phone' => $user->phone,
            'code' => $code,
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonStructure(['data' => ['reset_token']]);

        $resetToken = $verifyResponse->json('data.reset_token');

        $resetResponse = $this->postJson('/api/password-recovery/reset-password', [
            'phone' => $user->phone,
            'reset_token' => $resetToken,
            'password' => '5678',
            'password_confirmation' => '5678',
        ]);

        $resetResponse->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('5678', $user->password));
    }

    public function test_it_rejects_unknown_phone_when_requesting_recovery_code(): void
    {
        $response = $this->postJson('/api/password-recovery/request-code', [
            'phone' => '3999999999',
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('message', 'No existe una cuenta asociada a ese número de teléfono.');
    }
}