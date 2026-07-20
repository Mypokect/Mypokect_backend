<?php

namespace Tests\Unit;

use App\Models\PhoneVerification;
use Tests\TestCase;

class PhoneVerificationModelTest extends TestCase
{
    private function makeVerification(array $attributes = []): PhoneVerification
    {
        return new PhoneVerification(array_merge([
            'telefono' => '+573001234567',
            'codigo' => 'hash',
            'intentos' => 0,
            'verificado' => false,
            'expira_en' => now()->addMinutes(5),
            'enviado_en' => now(),
        ], $attributes));
    }

    public function test_is_expired(): void
    {
        $this->assertFalse($this->makeVerification()->isExpired());
        $this->assertTrue($this->makeVerification(['expira_en' => now()->subSecond()])->isExpired());
    }

    public function test_is_verified(): void
    {
        $this->assertFalse($this->makeVerification()->isVerified());
        $this->assertTrue($this->makeVerification(['verificado' => true])->isVerified());
    }

    public function test_can_retry_respeta_intentos_expiracion_y_estado(): void
    {
        $this->assertTrue($this->makeVerification()->canRetry());
        $this->assertTrue($this->makeVerification(['intentos' => PhoneVerification::MAX_ATTEMPTS - 1])->canRetry());

        $this->assertFalse($this->makeVerification(['intentos' => PhoneVerification::MAX_ATTEMPTS])->canRetry());
        $this->assertFalse($this->makeVerification(['expira_en' => now()->subSecond()])->canRetry());
        $this->assertFalse($this->makeVerification(['verificado' => true])->canRetry());
    }

    public function test_remaining_attempts(): void
    {
        $this->assertSame(5, $this->makeVerification()->remainingAttempts());
        $this->assertSame(2, $this->makeVerification(['intentos' => 3])->remainingAttempts());
        $this->assertSame(0, $this->makeVerification(['intentos' => 7])->remainingAttempts());
    }
}
