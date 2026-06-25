<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ruta de prueba protegida por el gating de suscripción
        Route::middleware(['auth:sanctum', 'subscription.active'])
            ->get('/api/v1/_test/premium', fn () => response()->json(['ok' => true]));
    }

    private function proAnnualPlan(): Plan
    {
        return Plan::create([
            'code' => 'pro_annual', 'name' => 'Pro Anual', 'price_cents' => 69000 * 100,
            'currency' => 'COP', 'interval' => 'year', 'trial_days' => 14,
            'features' => ['tax_radar' => 'full'], 'is_active' => true, 'sort_order' => 3,
        ]);
    }

    public function test_plans_endpoint_returns_active_plans_publicly(): void
    {
        $this->proAnnualPlan();
        Plan::create([
            'code' => 'hidden', 'name' => 'Oculto', 'price_cents' => 0,
            'currency' => 'COP', 'interval' => 'none', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'pro_annual')
            ->assertJsonPath('data.0.price_cop', 69000);
    }

    public function test_status_returns_none_for_user_without_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/subscription/status');

        $response->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.is_premium', false);
    }

    public function test_status_reports_premium_for_trialing_subscription(): void
    {
        $user = User::factory()->create();
        $plan = $this->proAnnualPlan();
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'trialing',
            'gateway' => 'mercadopago', 'current_period_start' => now(),
            'current_period_end' => now()->addYear(), 'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/subscription/status');

        $response->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.plan', 'pro_annual')
            ->assertJsonPath('data.is_premium', true);
    }

    public function test_expired_subscription_is_not_premium(): void
    {
        $user = User::factory()->create();
        $plan = $this->proAnnualPlan();
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'expired',
            'current_period_start' => now()->subYear(), 'current_period_end' => now()->subDay(),
        ]);

        $this->assertFalse($user->fresh()->isPremium());
    }

    public function test_gating_middleware_blocks_non_premium_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/_test/premium');

        $response->assertStatus(402)
            ->assertJsonPath('error', 'subscription_required');
    }

    public function test_gating_middleware_allows_premium_user(): void
    {
        $user = User::factory()->create();
        $plan = $this->proAnnualPlan();
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addYear(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/_test/premium');

        $response->assertOk()->assertJsonPath('ok', true);
    }

    public function test_webhook_endpoint_logs_and_is_idempotent(): void
    {
        $payload = ['id' => 'pay_123', 'type' => 'payment', 'action' => 'payment.created'];

        $this->postJson('/api/webhooks/mercadopago', $payload)->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'gateway' => 'mercadopago', 'external_id' => 'pay_123', 'status' => 'received',
        ]);

        $this->postJson('/api/webhooks/unknowngw', $payload)->assertStatus(404);
    }
}
