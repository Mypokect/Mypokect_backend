<?php

namespace Tests\Feature;

use App\Models\GoalContribution;
use App\Models\SavingGoal;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalContributionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SavingGoal $goal;

    private Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tag = Tag::factory()->for($this->user)->create(['name' => 'Viaje']);
        $this->goal = SavingGoal::factory()
            ->for($this->user)
            ->for($this->tag, 'tag')
            ->create([
                'name' => 'Viaje a París',
                'target_amount' => 5000000,
            ]);
    }

    /**
     * Test get all contributions for a goal.
     */
    public function test_index_returns_all_contributions(): void
    {
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->count(3)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/goal-contributions/{$this->goal->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'goal_id', 'goal_name', 'amount', 'description', 'date', 'created_at', 'updated_at'],
            ],
            'total',
        ]);

        $this->assertCount(3, $response['data']);
    }

    /**
     * Test index requires authentication.
     */
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson("/api/goal-contributions/{$this->goal->id}");

        $response->assertStatus(401);
    }

    /**
     * Test index returns 404 for non-existent goal.
     */
    public function test_index_returns_404_for_non_existent_goal(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/goal-contributions/9999');

        $response->assertStatus(404);
    }

    /**
     * Test index returns 404 if goal belongs to another user.
     */
    public function test_index_returns_404_if_goal_belongs_to_another_user(): void
    {
        $anotherUser = User::factory()->create();
        $anotherTag = Tag::factory()->for($anotherUser)->create();
        $anotherGoal = SavingGoal::factory()
            ->for($anotherUser)
            ->for($anotherTag, 'tag')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/goal-contributions/{$anotherGoal->id}");

        $response->assertStatus(404);
    }

    /**
     * Test create contribution with valid data.
     */
    public function test_store_creates_contribution(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/goal-contributions', [
                'goal_id' => $this->goal->id,
                'amount' => 500000,
                'description' => 'Primer abono',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'data' => ['id', 'goal_id', 'goal_name', 'amount', 'description', 'date'],
        ]);

        $this->assertDatabaseHas('goal_contributions', [
            'user_id' => $this->user->id,
            'goal_id' => $this->goal->id,
            'amount' => 500000,
        ]);
    }

    /**
     * Test store validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/goal-contributions', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('goal_id', $response['messages']);
        $this->assertArrayHasKey('amount', $response['messages']);
    }

    /**
     * Test store validates amount format.
     */
    public function test_store_validates_amount_format(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/goal-contributions', [
                'goal_id' => $this->goal->id,
                'amount' => 'invalid',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('amount', $response['messages']);
    }

    /**
     * Test store requires amount > 0.
     */
    public function test_store_requires_positive_amount(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/goal-contributions', [
                'goal_id' => $this->goal->id,
                'amount' => 0,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('amount', $response['messages']);
    }

    /**
     * Test store requires authentication.
     */
    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/goal-contributions', [
            'goal_id' => $this->goal->id,
            'amount' => 500000,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test store with goal from another user.
     */
    public function test_store_rejects_goal_from_another_user(): void
    {
        $anotherUser = User::factory()->create();
        $anotherTag = Tag::factory()->for($anotherUser)->create();
        $anotherGoal = SavingGoal::factory()
            ->for($anotherUser)
            ->for($anotherTag, 'tag')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/goal-contributions', [
                'goal_id' => $anotherGoal->id,
                'amount' => 500000,
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test delete contribution.
     */
    public function test_destroy_deletes_contribution(): void
    {
        $contribution = GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/goal-contributions/{$contribution->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('goal_contributions', [
            'id' => $contribution->id,
        ]);
    }

    /**
     * Test destroy requires authentication.
     */
    public function test_destroy_requires_authentication(): void
    {
        $contribution = GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->deleteJson("/api/goal-contributions/{$contribution->id}");

        $response->assertStatus(401);
    }

    /**
     * Test destroy returns 404 for non-existent contribution.
     */
    public function test_destroy_returns_404_for_non_existent_contribution(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/goal-contributions/9999');

        $response->assertStatus(404);
    }

    /**
     * Test destroy cannot delete contribution from another user.
     */
    public function test_destroy_cannot_delete_another_user_contribution(): void
    {
        $anotherUser = User::factory()->create();
        $contribution = GoalContribution::factory()
            ->for($anotherUser)
            ->for($this->goal, 'goal')
            ->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/goal-contributions/{$contribution->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('goal_contributions', [
            'id' => $contribution->id,
        ]);
    }

    /**
     * Test get statistics for a goal.
     */
    public function test_stats_returns_statistics(): void
    {
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create(['amount' => 100000]);
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create(['amount' => 200000]);
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create(['amount' => 150000]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/goal-contributions/{$this->goal->id}/stats");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'total_contributions',
            'total_amount',
            'average_contribution',
            'largest_contribution',
            'smallest_contribution',
            'last_contribution_date',
            'percentage_of_goal',
        ]);

        $this->assertEquals(3, $response['total_contributions']);
        $this->assertEquals(450000, $response['total_amount']);
        $this->assertEquals(200000, $response['largest_contribution']);
        $this->assertEquals(100000, $response['smallest_contribution']);
    }

    /**
     * Test stats calculates percentage correctly.
     */
    public function test_stats_calculates_percentage_correctly(): void
    {
        GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create(['amount' => 1000000]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/goal-contributions/{$this->goal->id}/stats");

        $response->assertStatus(200);
        // 1000000 / 5000000 = 20%
        $this->assertEquals(20, $response['percentage_of_goal']);
    }

    /**
     * Test stats requires authentication.
     */
    public function test_stats_requires_authentication(): void
    {
        $response = $this->getJson("/api/goal-contributions/{$this->goal->id}/stats");

        $response->assertStatus(401);
    }

    /**
     * Test stats returns 404 for non-existent goal.
     */
    public function test_stats_returns_404_for_non_existent_goal(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/goal-contributions/9999/stats');

        $response->assertStatus(404);
    }

    /**
     * Test contributions are returned in ASC order (oldest first).
     */
    public function test_index_returns_contributions_in_asc_order(): void
    {
        $contribution1 = GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create(['description' => 'First']);

        sleep(1);

        $contribution2 = GoalContribution::factory()
            ->for($this->user)
            ->for($this->goal, 'goal')
            ->create(['description' => 'Second']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/goal-contributions/{$this->goal->id}");

        $response->assertStatus(200);
        $this->assertEquals('First', $response['data'][0]['description']);
        $this->assertEquals('Second', $response['data'][1]['description']);
    }
}
