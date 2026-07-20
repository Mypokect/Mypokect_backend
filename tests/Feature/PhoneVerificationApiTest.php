<?php

namespace Tests\Feature;

use App\Events\PhoneVerificationFailed;
use App\Events\PhoneVerificationRequested;
use App\Events\PhoneVerified;
use App\Models\PhoneVerification;
use App\Services\PhoneVerification\Contracts\NotificationProviderInterface;
use App\Services\PhoneVerification\Providers\FakeNotificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhoneVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+573001234567';

    private FakeNotificationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeNotificationProvider;
        $this->app->instance(NotificationProviderInterface::class, $this->provider);
    }

    private function sendCode(string $phone = self::PHONE): TestResponse
    {
        return $this->postJson('/api/phone/send-code', ['telefono' => $phone]);
    }

    private function verify(string $code, string $phone = self::PHONE): TestResponse
    {
        return $this->postJson('/api/phone/verify', ['telefono' => $phone, 'codigo' => $code]);
    }

    public function test_envia_el_codigo_y_lo_guarda_hasheado(): void
    {
        Event::fake([PhoneVerificationRequested::class]);

        $this->sendCode()
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Código enviado'])
            ->assertJsonStructure(['success', 'message', 'expira_en', 'reintento_en_segundos']);

        $otp = $this->provider->lastOtpFor(self::PHONE);
        $this->assertNotNull($otp);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);

        $verification = PhoneVerification::sole();
        $this->assertNotSame($otp, $verification->codigo);
        $this->assertTrue(Hash::check($otp, $verification->codigo));
        $this->assertFalse($verification->verificado);

        Event::assertDispatched(PhoneVerificationRequested::class);
    }

    public function test_rechaza_telefonos_que_no_sean_e164(): void
    {
        foreach (['3001234567', '+0573001234567', '+57 300 123', 'abc', ''] as $invalid) {
            $this->sendCode($invalid)->assertStatus(422);
        }

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_verifica_el_codigo_correcto(): void
    {
        Event::fake([PhoneVerified::class]);

        $this->sendCode()->assertOk();
        $otp = $this->provider->lastOtpFor(self::PHONE);

        $this->verify($otp)
            ->assertOk()
            ->assertJson(['verified' => true, 'telefono' => self::PHONE]);

        $this->assertTrue(PhoneVerification::sole()->verificado);
        Event::assertDispatched(PhoneVerified::class);
    }

    public function test_rechaza_un_codigo_invalido_y_cuenta_el_intento(): void
    {
        Event::fake([PhoneVerificationFailed::class]);

        $this->sendCode()->assertOk();
        $otp = $this->provider->lastOtpFor(self::PHONE);
        $wrong = $otp === '000000' ? '111111' : '000000';

        $this->verify($wrong)->assertStatus(422);

        $this->assertSame(1, PhoneVerification::sole()->intentos);
        Event::assertDispatched(
            PhoneVerificationFailed::class,
            fn (PhoneVerificationFailed $event) => $event->reason === 'invalid_code'
        );
    }

    public function test_rechaza_un_codigo_expirado(): void
    {
        $this->sendCode()->assertOk();
        $otp = $this->provider->lastOtpFor(self::PHONE);

        $this->travel(6)->minutes();

        $this->verify($otp)
            ->assertStatus(422)
            ->assertJsonPath('message', 'El código expiró o no existe. Solicita uno nuevo.');
    }

    public function test_bloquea_tras_cinco_intentos_fallidos(): void
    {
        $this->sendCode()->assertOk();
        $otp = $this->provider->lastOtpFor(self::PHONE);
        $wrong = $otp === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->verify($wrong)->assertStatus(422);
        }

        // Quinto intento fallido: se agota el cupo.
        $this->verify($wrong)->assertStatus(429);

        // Incluso el código correcto ya no sirve: hay que pedir uno nuevo.
        $this->verify($otp)->assertStatus(429);
    }

    public function test_limita_el_envio_a_uno_por_minuto(): void
    {
        $this->sendCode()->assertOk();

        $this->sendCode()->assertStatus(429);
        $this->postJson('/api/phone/resend', ['telefono' => self::PHONE])->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postJson('/api/phone/resend', ['telefono' => self::PHONE])->assertOk();
    }

    public function test_el_reenvio_invalida_el_codigo_anterior(): void
    {
        $this->sendCode()->assertOk();
        $firstOtp = $this->provider->lastOtpFor(self::PHONE);

        $this->travel(61)->seconds();

        $this->postJson('/api/phone/resend', ['telefono' => self::PHONE])->assertOk();
        $secondOtp = $this->provider->lastOtpFor(self::PHONE);

        // El OTP viejo ya no valida contra el código vigente.
        $this->verify($firstOtp)->assertStatus(422);

        // El nuevo sí.
        $this->verify($secondOtp)->assertOk()->assertJson(['verified' => true]);
        $this->assertDatabaseCount('phone_verifications', 2);
    }

    public function test_no_envia_a_un_telefono_ya_verificado(): void
    {
        $this->sendCode()->assertOk();
        $this->verify($this->provider->lastOtpFor(self::PHONE))->assertOk();

        $this->travel(61)->seconds();

        $this->sendCode()
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_si_el_proveedor_falla_no_queda_codigo_ni_cooldown(): void
    {
        $this->provider->shouldFail = true;
        $this->sendCode()->assertStatus(502);
        $this->assertDatabaseCount('phone_verifications', 0);

        // El fallo no consumió el cooldown: reintentar de inmediato funciona.
        $this->provider->shouldFail = false;
        $this->sendCode()->assertOk();
    }
}
