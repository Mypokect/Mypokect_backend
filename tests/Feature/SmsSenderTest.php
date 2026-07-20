<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsSenderTest extends TestCase
{
    use RefreshDatabase;

    private function useTwilioDriver(): void
    {
        config()->set('services.sms.driver', 'twilio');
        config()->set('services.sms.twilio.sid', 'AC_test_sid');
        config()->set('services.sms.twilio.token', 'test_token');
        config()->set('services.sms.twilio.from', '+15550001111');
    }

    public function test_login_sends_the_code_to_the_phone_via_twilio(): void
    {
        $this->useTwilioDriver();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        $user = User::factory()->create([
            'phone' => '3001234567',
            'country_code' => 'CO',
            'password' => Hash::make('1234'),
        ]);

        $response = $this->postJson('/api/login', [
            'phone' => $user->phone,
            'password' => '1234',
        ])->assertOk();

        $code = $response->json('data.debug_code');

        Http::assertSent(function (Request $request) use ($code) {
            return str_contains($request->url(), 'api.twilio.com')
                && $request['To'] === '+573001234567'
                && $request['From'] === '+15550001111'
                && str_contains($request['Body'], $code);
        });
    }

    public function test_register_sends_the_code_with_plus_prefix_country_code(): void
    {
        $this->useTwilioDriver();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        $this->postJson('/api/register', [
            'name' => 'Nuevo Usuario',
            'phone' => '3017654321',
            'country_code' => '+57',
            'password' => '1234',
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request['To'] === '+573017654321');
    }

    public function test_login_fails_gracefully_when_twilio_rejects_the_message(): void
    {
        $this->useTwilioDriver();
        Http::fake([
            'api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'Invalid To number'], 400),
        ]);

        $user = User::factory()->create([
            'phone' => '3001234567',
            'country_code' => 'CO',
            'password' => Hash::make('1234'),
        ]);

        $this->postJson('/api/login', [
            'phone' => $user->phone,
            'password' => '1234',
        ])->assertStatus(500);
    }

    public function test_log_driver_does_not_call_twilio(): void
    {
        config()->set('services.sms.driver', 'log');
        Http::fake();

        $user = User::factory()->create([
            'phone' => '3001234567',
            'country_code' => 'CO',
            'password' => Hash::make('1234'),
        ]);

        $this->postJson('/api/login', [
            'phone' => $user->phone,
            'password' => '1234',
        ])->assertOk();

        Http::assertNothingSent();
    }
}
