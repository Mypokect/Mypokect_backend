<?php

namespace Tests\Feature;

use App\Models\GoalContribution;
use App\Models\Movement;
use App\Models\SavingGoal;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tag $expenseTag;

    private Tag $goalTag;

    private SavingGoal $goal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->expenseTag = Tag::factory()->for($this->user)->create(['name' => 'Comida']);
        $this->goalTag = Tag::factory()->for($this->user)->create(['name' => 'Viaje']);
        $this->goal = SavingGoal::factory()
            ->for($this->user)
            ->for($this->goalTag, 'tag')
            ->create(['name' => 'Viaje a París']);
    }

    /**
     * Test unified transactions endpoint returns mixed data.
     */
    public function test_unified_returns_mixed_transactions(): void
    {
        // Create expense
        Movement::factory()
            ->for($this->user)
            ->for($this->expenseTag, 'tag')
            ->create([
                'type' => 'expense',
                'amount' => 50000,
                'description' => 'Lunch',
                'payment_method' => 'cash',
            ]);

        // Create income
        Movement::factory()
            ->for($this->user)
            ->create([
                'type' => 'income',
                'amount' => 2000000,
                'description' => 'Salary',
                'payment_method' => 'digital',
                'tag_id' => null,
            ]);

        // Create contribution
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create([
                'amount' => 500000,
                'description' => 'First abono',
            ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type',
                    'type_badge',
                    'amount',
                    'description',
                    'category',
                    'goal_name',
                    'date',
                    'payment_method',
                    'source',
                ],
            ],
            'pagination' => [
                'total',
                'per_page',
                'current_page',
                'last_page',
                'from',
                'to',
            ],
        ]);

        $this->assertCount(3, $response['data']);
        $this->assertEquals(3, $response['pagination']['total']);
    }

    /**
     * Test unified transactions are sorted ASC (oldest first).
     */
    public function test_unified_transactions_sorted_asc(): void
    {
        $expense = Movement::factory()
            ->for($this->user)
            ->create([
                'type' => 'expense',
                'description' => 'First expense',
                'created_at' => now()->subDays(2),
            ]);

        $contribution = GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create([
                'description' => 'Middle contribution',
                'created_at' => now()->subDay(),
            ]);

        $income = Movement::factory()
            ->for($this->user)
            ->create([
                'type' => 'income',
                'description' => 'Last income',
                'created_at' => now(),
            ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertEquals('First expense', $response['data'][0]['description']);
        $this->assertEquals('Middle contribution', $response['data'][1]['description']);
        $this->assertEquals('Last income', $response['data'][2]['description']);
    }

    /**
     * Test filter by type (expense only).
     */
    public function test_filter_by_type_expense(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'expense']);
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'income']);
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?type=expense');

        $response->assertStatus(200);
        $this->assertCount(1, $response['data']);
        $this->assertEquals('expense', $response['data'][0]['type']);
    }

    /**
     * Test filter by type (contribution only).
     */
    public function test_filter_by_type_contribution(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'expense']);
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'income']);
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?type=contribution');

        $response->assertStatus(200);
        $this->assertCount(1, $response['data']);
        $this->assertEquals('contribution', $response['data'][0]['type']);
    }

    /**
     * Test filter by date range.
     */
    public function test_filter_by_date_range(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create([
                'type' => 'expense',
                'created_at' => now()->subDays(10),
            ]);
        Movement::factory()
            ->for($this->user)
            ->create([
                'type' => 'income',
                'created_at' => now(),
            ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?start_date='.now()->subDays(5)->toDateString().'&end_date='.now()->toDateString());

        $response->assertStatus(200);
        $this->assertCount(1, $response['data']);
    }

    /**
     * Test filter by goal_id (contributions only).
     */
    public function test_filter_by_goal_id(): void
    {
        $otherTag = Tag::factory()->for($this->user)->create();
        $otherGoal = SavingGoal::factory()
            ->for($this->user)
            ->for($otherTag, 'tag')
            ->create();

        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();
        GoalContribution::factory()
            ->for($this->user)
            ->for($otherGoal, 'goal')
            ->create();
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'expense']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/transactions/unified?type=contribution&goal_id={$this->goal->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response['data']);
        $this->assertEquals('contribution', $response['data'][0]['type']);
        $this->assertEquals('Viaje a París', $response['data'][0]['goal_name']);
    }

    /**
     * Test pagination.
     */
    public function test_pagination(): void
    {
        // Create 75 transactions
        Movement::factory()
            ->for($this->user)
            ->count(75)
            ->create(['type' => 'expense']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?page=1&per_page=50');

        $response->assertStatus(200);
        $this->assertCount(50, $response['data']);
        $this->assertEquals(1, $response['pagination']['current_page']);
        $this->assertEquals(2, $response['pagination']['last_page']);

        $response2 = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?page=2&per_page=50');

        $response2->assertStatus(200);
        $this->assertCount(25, $response2['data']);
        $this->assertEquals(2, $response2['pagination']['current_page']);
    }

    /**
     * Test custom per_page values.
     */
    public function test_custom_per_page(): void
    {
        Movement::factory()
            ->for($this->user)
            ->count(25)
            ->create(['type' => 'expense']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?per_page=10');

        $response->assertStatus(200);
        $this->assertCount(10, $response['data']);
        $this->assertEquals(10, $response['pagination']['per_page']);
        $this->assertEquals(3, $response['pagination']['last_page']);
    }

    /**
     * Test invalid per_page caps at 100.
     */
    public function test_invalid_per_page_caps_at_100(): void
    {
        Movement::factory()
            ->for($this->user)
            ->count(150)
            ->create(['type' => 'expense']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified?per_page=999');

        $response->assertStatus(200);
        $this->assertEquals(100, $response['pagination']['per_page']);
        $this->assertCount(100, $response['data']);
    }

    /**
     * Test requires authentication.
     */
    public function test_unified_requires_authentication(): void
    {
        $response = $this->getJson('/api/transactions/unified');

        $response->assertStatus(401);
    }

    /**
     * Test expense has correct badge.
     */
    public function test_expense_has_correct_badge(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'expense']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertEquals('Gasto', $response['data'][0]['type_badge']);
    }

    /**
     * Test income has correct badge.
     */
    public function test_income_has_correct_badge(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create(['type' => 'income']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertEquals('Ingreso', $response['data'][0]['type_badge']);
    }

    /**
     * Test contribution has correct badge.
     */
    public function test_contribution_has_correct_badge(): void
    {
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertEquals('Abono', $response['data'][0]['type_badge']);
    }

    /**
     * Test expense has category and no goal_name.
     */
    public function test_expense_has_category_no_goal(): void
    {
        Movement::factory()
            ->for($this->user)
            ->for($this->expenseTag, 'tag')
            ->create(['type' => 'expense']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertEquals('Comida', $response['data'][0]['category']);
        $this->assertNull($response['data'][0]['goal_name']);
    }

    /**
     * Test contribution has goal_name and no category.
     */
    public function test_contribution_has_goal_no_category(): void
    {
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertNull($response['data'][0]['category']);
        $this->assertEquals('Viaje a París', $response['data'][0]['goal_name']);
    }

    /**
     * Test contribution has payment_method null.
     */
    public function test_contribution_has_null_payment_method(): void
    {
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertNull($response['data'][0]['payment_method']);
    }

    /**
     * Test expense has payment_method.
     */
    public function test_expense_has_payment_method(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create([
                'type' => 'expense',
                'payment_method' => 'cash',
            ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/transactions/unified');

        $response->assertStatus(200);
        $this->assertEquals('cash', $response['data'][0]['payment_method']);
    }
}
