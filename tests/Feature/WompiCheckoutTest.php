<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WompiCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.wompi', [
            'public_key'       => 'pub_test_abc',
            'private_key'      => 'prv_test_abc',
            'integrity_secret' => 'integ_secret',
            'events_secret'    => 'events_secret',
            'environment'      => 'sandbox',
            'redirect_url'     => 'http://localhost:5173/suscripcion/gracias',
        ]);
    }

    private function proMonthly(): Plan
    {
        return Plan::create([
            'code' => 'pro_monthly', 'name' => 'Pro Mensual', 'price_cents' => 8900 * 100,
            'currency' => 'COP', 'interval' => 'month', 'trial_days' => 14,
            'features' => ['tax_radar' => 'full'], 'is_active' => true, 'sort_order' => 2,
        ]);
    }

    public function test_checkout_returns_signed_wompi_url_and_creates_pending_payment(): void
    {
        $user = User::factory()->create(['email' => 'pay@example.com']);
        $plan = $this->proMonthly();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/subscription/checkout', ['plan' => 'pro_monthly']);

        $response->assertOk()->assertJsonPath('data.plan', 'pro_monthly');

        $url = $response->json('data.checkout_url');
        $this->assertStringContainsString('https://checkout.wompi.co/p/', $url);
        $this->assertStringContainsString('public-key=pub_test_abc', $url);
        $this->assertStringContainsString('amount-in-cents=890000', $url);
        $this->assertStringContainsString('signature%3Aintegrity=', $url); // 'signature:integrity'

        $reference = $response->json('data.reference');
        $this->assertDatabaseHas('billing_payments', [
            'user_id' => $user->id, 'gateway' => 'wompi',
            'reference' => $reference, 'status' => 'pending', 'amount_cents' => 890000,
        ]);
    }

    public function test_free_plan_cannot_checkout(): void
    {
        $user = User::factory()->create();
        Plan::create([
            'code' => 'free', 'name' => 'Gratis', 'price_cents' => 0,
            'currency' => 'COP', 'interval' => 'none', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/subscription/checkout', ['plan' => 'free'])
            ->assertStatus(422);
    }

    public function test_valid_webhook_activates_subscription(): void
    {
        $user = User::factory()->create(['email' => 'pay@example.com']);
        $plan = $this->proMonthly();
        $subscription = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'gateway' => 'wompi',
        ]);
        $payment = BillingPayment::create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'gateway' => 'wompi', 'reference' => 'subREF123', 'amount_cents' => 890000,
            'currency' => 'COP', 'status' => 'pending',
        ]);

        $payload = $this->signedPayload('wtx_900', 'APPROVED', 'subREF123', 890000);
        $this->postJson('/api/webhooks/wompi', $payload)->assertOk();

        $this->assertDatabaseHas('billing_payments', [
            'id' => $payment->id, 'status' => 'approved', 'gateway_payment_id' => 'wtx_900', 'method' => 'card',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id, 'status' => 'active', 'gateway' => 'wompi',
        ]);
        $this->assertTrue($user->fresh()->isPremium());
        $this->assertDatabaseHas('invoices', ['payment_id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseHas('webhook_logs', [
            'gateway' => 'wompi', 'external_id' => 'wtx_900', 'status' => 'processed', 'signature_valid' => true,
        ]);
    }

    public function test_webhook_is_idempotent(): void
    {
        $user = User::factory()->create();
        $plan = $this->proMonthly();
        $subscription = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'gateway' => 'wompi',
        ]);
        BillingPayment::create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'gateway' => 'wompi', 'reference' => 'subREF999', 'amount_cents' => 890000,
            'currency' => 'COP', 'status' => 'pending',
        ]);

        $payload = $this->signedPayload('wtx_999', 'APPROVED', 'subREF999', 890000);
        $this->postJson('/api/webhooks/wompi', $payload)->assertOk();
        $this->postJson('/api/webhooks/wompi', $payload)->assertOk();

        // Un solo charge en el libro mayor pese a dos webhooks.
        $this->assertDatabaseCount('billing_transactions', 1);
    }

    public function test_invalid_checksum_is_not_processed(): void
    {
        $user = User::factory()->create();
        $plan = $this->proMonthly();
        $subscription = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'gateway' => 'wompi',
        ]);
        BillingPayment::create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'gateway' => 'wompi', 'reference' => 'subBAD', 'amount_cents' => 890000,
            'currency' => 'COP', 'status' => 'pending',
        ]);

        $payload = $this->signedPayload('wtx_bad', 'APPROVED', 'subBAD', 890000);
        $payload['signature']['checksum'] = 'deadbeef'; // firma corrupta

        $this->postJson('/api/webhooks/wompi', $payload)->assertOk();

        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'trialing']);
        $this->assertDatabaseHas('webhook_logs', [
            'gateway' => 'wompi', 'external_id' => 'wtx_bad', 'status' => 'ignored', 'signature_valid' => false,
        ]);
    }

    /** Construye un payload de Wompi con checksum válido (events_secret de la config de test). */
    private function signedPayload(string $txId, string $status, string $reference, int $amount): array
    {
        $timestamp = 1700000000;
        $properties = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
        $concat = $txId.$status.$amount.$timestamp.'events_secret';
        $checksum = hash('sha256', $concat);

        return [
            'event' => 'transaction.updated',
            'data'  => ['transaction' => [
                'id' => $txId, 'status' => $status, 'reference' => $reference,
                'amount_in_cents' => $amount, 'payment_method_type' => 'CARD',
            ]],
            'timestamp' => $timestamp,
            'signature' => ['properties' => $properties, 'checksum' => $checksum],
        ];
    }
}
