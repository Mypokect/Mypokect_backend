<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\Movement;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user and get token
        $this->user = User::factory()->create([
            'phone' => '+573001234567',
            'password' => bcrypt('password'),
        ]);

        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    /**
     * Test: Create manual budget (MODO 1)
     */
    public function test_create_manual_budget(): void
    {
        $payload = [
            'title' => 'Viaje a Machu Picchu',
            'description' => 'Vacaciones de verano',
            'total_amount' => 2000,
            'categories' => [
                ['name' => 'Vuelos', 'amount' => 800, 'reason' => 'Pasajes aéreos'],
                ['name' => 'Alojamiento', 'amount' => 600, 'reason' => 'Hotel'],
                ['name' => 'Comida', 'amount' => 400, 'reason' => 'Restaurantes'],
                ['name' => 'Actividades', 'amount' => 200, 'reason' => 'Tours'],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/manual', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.budget.mode', 'manual')
            ->assertJsonPath('data.budget.status', 'active')
            ->assertJsonPath('data.budget.language', 'es')
            ->assertJsonPath('data.budget.plan_type', 'travel')
            ->assertJsonPath('data.is_valid', true);

        $this->assertDatabaseHas('budgets', [
            'user_id' => $this->user->id,
            'title' => 'Viaje a Machu Picchu',
            'total_amount' => 2000,
            'mode' => 'manual',
        ]);

        $this->assertDatabaseCount('budget_categories', 4);

        $budget = Budget::first();
        $this->assertNotNull($budget);

        foreach ($payload['categories'] as $index => $categoryData) {
            $this->assertDatabaseHas('budget_categories', [
                'budget_id' => $budget->id,
                'name' => $categoryData['name'],
                'amount' => $categoryData['amount'],
                'order' => $index,
            ]);
        }
    }

    /**
     * Test: Create manual budget with invalid sum
     */
    public function test_create_manual_budget_with_invalid_sum(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/manual', [
                'title' => 'Test Budget',
                'total_amount' => 1000,
                'categories' => [
                    ['name' => 'Item 1', 'amount' => 400],
                    ['name' => 'Item 2', 'amount' => 300],
                    // Sum is 700, but total is 1000 - should fail
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test: Generate AI suggestions (MODO 2 - Step 1)
     */
    public function test_generate_ai_budget_suggestions(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/ai/generate', [
                'title' => 'Fiesta de cumpleaños',
                'description' => 'Fiesta de cumpleaños con 50 personas',
                'total_amount' => 1500,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [
                'title',
                'total_amount',
                'categories' => [
                    '*' => ['name', 'amount', 'reason'],
                ],
                'language',
                'plan_type',
                'general_advice',
            ],
        ]);

        // Verify categories sum equals total
        $categories = $response->json('data.categories');
        $sum = array_sum(array_column($categories, 'amount'));
        $this->assertEqualsWithDelta($sum, 1500, 0.01);
    }

    /**
     * Test: Save AI budget (MODO 2 - Step 2)
     */
    public function test_save_ai_budget(): void
    {
        $payload = [
            'title' => 'Fiesta de cumpleaños',
            'description' => 'Cumpleaños con 50 personas',
            'total_amount' => 1500,
            'language' => 'es',
            'plan_type' => 'party',
            'categories' => [
                ['name' => 'Lugar', 'amount' => 400, 'reason' => 'Salón'],
                ['name' => 'Comida', 'amount' => 700, 'reason' => 'Catering'],
                ['name' => 'Decoración', 'amount' => 250, 'reason' => 'Flores y globos'],
                ['name' => 'Entretenimiento', 'amount' => 150, 'reason' => 'DJ'],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/ai/save', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.budget.mode', 'ai')
            ->assertJsonPath('data.budget.plan_type', 'party')
            ->assertJsonPath('data.is_valid', true);

        $this->assertDatabaseHas('budgets', [
            'user_id' => $this->user->id,
            'title' => $payload['title'],
            'total_amount' => $payload['total_amount'],
            'mode' => 'ai',
        ]);

        $this->assertDatabaseCount('budget_categories', 4);

        $budget = Budget::first();
        $this->assertNotNull($budget);

        foreach ($payload['categories'] as $index => $categoryData) {
            $this->assertDatabaseHas('budget_categories', [
                'budget_id' => $budget->id,
                'name' => $categoryData['name'],
                'amount' => $categoryData['amount'],
                'order' => $index,
            ]);
        }
    }

    /**
     * Test: Get all budgets
     */
    public function test_get_all_budgets(): void
    {
        // Create test budgets
        Budget::factory()
            ->for($this->user)
            ->has(BudgetCategory::factory()->count(3), 'categories')
            ->count(5)
            ->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/budgets');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'title', 'total_amount', 'mode', 'categories'],
            ],
        ]);
    }

    /**
     * Test: Get single budget
     */
    public function test_get_single_budget(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 1000]);

        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 600,
            'order' => 0,
        ]);
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 400,
            'order' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.budget.id', $budget->id);
        $response->assertJsonPath('data.is_valid', true);
    }

    /**
     * Test: Unauthorized access to other user's budget
     */
    public function test_cannot_access_other_user_budget(): void
    {
        $otherUser = User::factory()->create();
        $budget = Budget::factory()
            ->for($otherUser)
            ->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test: Update budget
     */
    public function test_update_budget(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 1000]);

        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 600,
            'order' => 0,
        ]);
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 400,
            'order' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/budgets/{$budget->id}", [
                'title' => 'Updated Title',
                'total_amount' => 1500,
                'categories' => [
                    ['name' => 'Category A', 'amount' => 900],
                    ['name' => 'Category B', 'amount' => 600],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.title', 'Updated Title');
    }

    /**
     * Test: Delete budget
     */
    public function test_delete_budget(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->has(BudgetCategory::factory()->count(3), 'categories')
            ->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/budgets/{$budget->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Budget and categories are soft-deleted
        $this->assertSoftDeleted($budget);
        $this->assertEquals(0, BudgetCategory::where('budget_id', $budget->id)->count());
    }

    /**
     * Test: Add category to budget
     */
    public function test_add_category_to_budget(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 1000]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/budgets/{$budget->id}/categories", [
                'name' => 'New Category',
                'amount' => 300,
                'reason' => 'Test category',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.name', 'New Category');
        $response->assertJsonPath('data.amount', '300.00');
    }

    /**
     * Test: Add category exceeding budget
     */
    public function test_cannot_add_category_exceeding_budget(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 1000]);

        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 700,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/budgets/{$budget->id}/categories", [
                'name' => 'Exceeding Category',
                'amount' => 400, // 700 + 400 = 1100 > 1000
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    /**
     * Test: Update category
     */
    public function test_update_category(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 1000]);

        $category = BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 500,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/budgets/{$budget->id}/categories/{$category->id}", [
                'name' => 'Updated Category',
                'amount' => 600,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Updated Category');
        $response->assertJsonPath('data.amount', '600.00');
    }

    /**
     * Test: Delete category
     */
    public function test_delete_category(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create();

        $categories = BudgetCategory::factory()->count(2)->create([
            'budget_id' => $budget->id,
        ]);

        $category = $categories->first();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/budgets/{$budget->id}/categories/{$category->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Category is soft-deleted, so not found by default scope
        $this->assertEquals(0, BudgetCategory::where('id', $category->id)->count());
    }

    /**
     * Test: Validate budget
     */
    public function test_validate_budget(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 1000]);

        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 600,
        ]);
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'amount' => 400,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/budgets/{$budget->id}/validate");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [
                'is_valid',
                'categories_total',
                'total_amount',
                'difference',
                'message',
            ],
        ]);
        $response->assertJsonPath('data.is_valid', true);
    }

    /**
     * Test: Language detection - Spanish
     */
    public function test_language_detection_spanish(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/manual', [
                'title' => 'Viaje a Perú',
                'description' => 'Vacaciones de verano',
                'total_amount' => 2000,
                'categories' => [
                    ['name' => 'Transporte', 'amount' => 1000],
                    ['name' => 'Hospedaje', 'amount' => 1000],
                ],
            ]);

        $response->assertJsonPath('data.budget.language', 'es');
    }

    /**
     * Test: Language detection - English
     */
    public function test_language_detection_english(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/manual', [
                'title' => 'Travel to Peru',
                'description' => 'Summer vacation',
                'total_amount' => 2000,
                'categories' => [
                    ['name' => 'Transportation', 'amount' => 1000],
                    ['name' => 'Accommodation', 'amount' => 1000],
                ],
            ]);

        $response->assertJsonPath('data.budget.language', 'en');
    }

    /**
     * Test: Plan type detection
     */
    public function test_plan_type_detection(): void
    {
        $testCases = [
            'Viaje a playa' => 'travel',
            'Evento corporativo' => 'event',
            'Fiesta de cumpleaños' => 'party',
            'Compra de laptop' => 'purchase',
            'Reforma del baño' => 'project',
        ];

        foreach ($testCases as $description => $expectedType) {
            $response = $this->withHeader('Authorization', "Bearer {$this->token}")
                ->postJson('/api/budgets/manual', [
                    'title' => 'Test',
                    'description' => $description,
                    'total_amount' => 1000,
                    'categories' => [
                        ['name' => 'Cat1', 'amount' => 1000],
                    ],
                ]);

            $response->assertJsonPath('data.budget.plan_type', $expectedType,
                "Failed for description: $description");
        }
    }

    /**
     * Test: Create manual budget with linked_tags
     */
    public function test_create_manual_budget_with_linked_tags(): void
    {
        $payload = [
            'title' => 'Quincena Febrero',
            'description' => 'Gastos de la primera quincena',
            'total_amount' => 3500,
            'categories' => [
                [
                    'name' => 'Comida',
                    'amount' => 2000,
                    'reason' => 'Comidas diarias',
                    'linked_tags' => ['Comida', 'Restaurante', 'Mercado'],
                ],
                [
                    'name' => 'Transporte',
                    'amount' => 1000,
                    'reason' => null,
                    'linked_tags' => ['Uber', 'Gasolina'],
                ],
                [
                    'name' => 'Entretenimiento',
                    'amount' => 500,
                    'reason' => null,
                    'linked_tags' => [],
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/manual', $payload);

        $response->assertStatus(201);

        $budget = Budget::first();
        $this->assertNotNull($budget);

        $categories = $budget->categories()->orderBy('order')->get();

        $this->assertEquals(['Comida', 'Restaurante', 'Mercado'], $categories[0]->linked_tags);
        $this->assertEquals(['Uber', 'Gasolina'], $categories[1]->linked_tags);
        $this->assertEquals([], $categories[2]->linked_tags);
    }

    /**
     * Test: Update budget preserves linked_tags
     */
    public function test_update_budget_with_linked_tags(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create(['total_amount' => 2000]);

        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Comida',
            'amount' => 1200,
            'percentage' => 60,
            'linked_tags' => ['Comida'],
            'order' => 0,
        ]);
        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Transporte',
            'amount' => 800,
            'percentage' => 40,
            'linked_tags' => [],
            'order' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/budgets/{$budget->id}", [
                'title' => 'Quincena Editada',
                'total_amount' => 2000,
                'categories' => [
                    [
                        'name' => 'Comida',
                        'amount' => 1200,
                        'linked_tags' => ['Comida', 'Restaurante'],
                    ],
                    [
                        'name' => 'Transporte',
                        'amount' => 800,
                        'linked_tags' => ['Uber'],
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $categories = $budget->fresh()->categories()->orderBy('order')->get();

        $this->assertEquals(['Comida', 'Restaurante'], $categories[0]->linked_tags);
        $this->assertEquals(['Uber'], $categories[1]->linked_tags);
    }

    /**
     * Test: Linked_tags are returned when listing budgets
     */
    public function test_list_budgets_returns_linked_tags(): void
    {
        $budget = Budget::factory()
            ->for($this->user)
            ->create();

        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Comida',
            'amount' => 500,
            'percentage' => 100,
            'linked_tags' => ['Comida', 'Mercado'],
            'order' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/budgets');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.categories.0.linked_tags', ['Comida', 'Mercado']);
    }

    /**
     * Test: Get spending calculation based on linked_tags
     */
    public function test_get_spending(): void
    {
        // Create tags
        $tagComida = Tag::factory()->for($this->user)->create(['name' => 'Comida']);
        $tagRestaurante = Tag::factory()->for($this->user)->create(['name' => 'Restaurante']);
        $tagUber = Tag::factory()->for($this->user)->create(['name' => 'Uber']);

        // Create movements (expenses)
        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 150.00,
            'tag_id' => $tagComida->id,
            'created_at' => '2026-02-05 10:00:00',
        ]);
        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 200.50,
            'tag_id' => $tagRestaurante->id,
            'created_at' => '2026-02-06 12:00:00',
        ]);
        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 80.00,
            'tag_id' => $tagUber->id,
            'created_at' => '2026-02-07 08:00:00',
        ]);

        // Movement outside date range (should NOT count)
        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 500.00,
            'tag_id' => $tagComida->id,
            'created_at' => '2026-01-15 10:00:00',
        ]);

        // Income movement (should NOT count)
        Movement::factory()->for($this->user)->create([
            'type' => 'income',
            'amount' => 1000.00,
            'tag_id' => $tagComida->id,
            'created_at' => '2026-02-05 10:00:00',
        ]);

        // Create budget with linked_tags
        $budget = Budget::factory()->for($this->user)->create(['total_amount' => 3000]);

        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Comida',
            'amount' => 2000,
            'percentage' => 66.67,
            'linked_tags' => ['Comida', 'Restaurante'],
            'order' => 0,
        ]);
        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Transporte',
            'amount' => 1000,
            'percentage' => 33.33,
            'linked_tags' => ['Uber'],
            'order' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-09");

        $response->assertStatus(200);
        $response->assertJsonPath('data.budget_id', $budget->id);
        $response->assertJsonPath('data.total_budgeted', 3000);

        // Comida: 150 + 200.50 = 350.50
        $response->assertJsonPath('data.categories.0.name', 'Comida');
        $response->assertJsonPath('data.categories.0.budgeted', 2000);
        $response->assertJsonPath('data.categories.0.spent', 350.50);

        // Transporte: 80
        $response->assertJsonPath('data.categories.1.name', 'Transporte');
        $response->assertJsonPath('data.categories.1.budgeted', 1000);
        $response->assertJsonPath('data.categories.1.spent', 80);

        // Total spent: 350.50 + 80 = 430.50
        $response->assertJsonPath('data.total_spent', 430.5);
    }

    /**
     * Test: Spending with no linked_tags returns zero spent
     */
    public function test_get_spending_no_linked_tags(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Comida']);

        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 500.00,
            'tag_id' => $tag->id,
            'created_at' => '2026-02-05 10:00:00',
        ]);

        $budget = Budget::factory()->for($this->user)->create(['total_amount' => 1000]);

        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Sin vincular',
            'amount' => 1000,
            'percentage' => 100,
            'linked_tags' => [],
            'order' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-09");

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_spent', 0);
        $response->assertJsonPath('data.categories.0.spent', 0);
    }

    /**
     * Test: Cannot access spending for another user's budget
     */
    public function test_cannot_access_other_user_spending(): void
    {
        $otherUser = User::factory()->create();
        $budget = Budget::factory()->for($otherUser)->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-09");

        $response->assertStatus(403);
    }

    /**
     * Test: linked_tags_since is set when creating budget with linked_tags
     */
    public function test_linked_tags_since_set_on_create(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/budgets/manual', [
                'title' => 'Test Since',
                'total_amount' => 1000,
                'categories' => [
                    ['name' => 'Con tags', 'amount' => 600, 'linked_tags' => ['Comida']],
                    ['name' => 'Sin tags', 'amount' => 400, 'linked_tags' => []],
                ],
            ]);

        $response->assertStatus(201);

        $categories = Budget::first()->categories()->orderBy('order')->get();

        $this->assertNotNull($categories[0]->linked_tags_since);
        $this->assertNull($categories[1]->linked_tags_since);
    }

    /**
     * Test: linked_tags_since is set when tags are added to a category that had none
     */
    public function test_linked_tags_since_set_on_update(): void
    {
        $budget = Budget::factory()->for($this->user)->create(['total_amount' => 1000]);

        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Sin tags',
            'amount' => 1000,
            'percentage' => 100,
            'linked_tags' => [],
            'linked_tags_since' => null,
            'order' => 0,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/budgets/{$budget->id}", [
                'title' => $budget->title,
                'total_amount' => 1000,
                'categories' => [
                    ['name' => 'Ahora con tags', 'amount' => 1000, 'linked_tags' => ['Comida']],
                ],
            ]);

        $category = $budget->fresh()->categories->first();

        $this->assertNotNull($category->linked_tags_since);
    }

    /**
     * Test: Spending only counts movements after linked_tags_since
     */
    public function test_spending_respects_linked_tags_since(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Comida']);

        // Movement BEFORE linked_tags_since (should NOT count)
        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 300,
            'tag_id' => $tag->id,
            'created_at' => '2026-02-03 10:00:00',
        ]);

        // Movement AFTER linked_tags_since (should count)
        Movement::factory()->for($this->user)->create([
            'type' => 'expense',
            'amount' => 200,
            'tag_id' => $tag->id,
            'created_at' => '2026-02-07 10:00:00',
        ]);

        $budget = Budget::factory()->for($this->user)->create(['total_amount' => 2000]);

        BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => 'Comida',
            'amount' => 2000,
            'percentage' => 100,
            'linked_tags' => ['Comida'],
            'linked_tags_since' => '2026-02-05 12:00:00',
            'order' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-09");

        $response->assertStatus(200);
        // Only the 200 movement (after Feb 5) should count, not the 300 (before Feb 5)
        $response->assertJsonPath('data.categories.0.spent', 200);
        $response->assertJsonPath('data.total_spent', 200);
    }

    /**
     * Test: Shared tags distribute spending proportionally instead of double-counting.
     */
    public function test_shared_tags_proportional_distribution(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Restaurante']);

        // Budget with 2 categories sharing the same tag
        $budget = Budget::factory()->for($this->user)->create([
            'total_amount' => 5000,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        // Comida: $3000 (60%), Entretenimiento: $2000 (40%)
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'name' => 'Comida',
            'amount' => 3000,
            'linked_tags' => ['Restaurante'],
            'linked_tags_since' => now(),
            'order' => 0,
        ]);
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'name' => 'Entretenimiento',
            'amount' => 2000,
            'linked_tags' => ['Restaurante'],
            'linked_tags_since' => now(),
            'order' => 1,
        ]);

        // $1000 spent with tag "Restaurante"
        Movement::factory()->create([
            'user_id' => $this->user->id,
            'tag_id' => $tag->id,
            'amount' => 1000,
            'type' => 'expense',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-28");

        $response->assertStatus(200);

        // Total should be $1000 (NOT $2000 from double-counting)
        $response->assertJsonPath('data.total_spent', 1000);

        // Comida gets 60% = $600, Entretenimiento gets 40% = $400
        $response->assertJsonPath('data.categories.0.spent', 600);
        $response->assertJsonPath('data.categories.1.spent', 400);

        // shared_tags should contain "Restaurante"
        $response->assertJsonPath('data.shared_tags.0', 'Restaurante');

        // Both categories should be marked as shared
        $response->assertJsonPath('data.categories.0.is_shared', true);
        $response->assertJsonPath('data.categories.1.is_shared', true);
    }

    /**
     * Test: No shared tags — behavior identical to before.
     */
    public function test_no_shared_tags_returns_empty_array(): void
    {
        $tag1 = Tag::factory()->for($this->user)->create(['name' => 'Comida']);
        $tag2 = Tag::factory()->for($this->user)->create(['name' => 'Uber']);

        $budget = Budget::factory()->for($this->user)->create([
            'total_amount' => 3000,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'name' => 'Comida',
            'amount' => 2000,
            'linked_tags' => ['Comida'],
            'linked_tags_since' => now(),
            'order' => 0,
        ]);
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'name' => 'Transporte',
            'amount' => 1000,
            'linked_tags' => ['Uber'],
            'linked_tags_since' => now(),
            'order' => 1,
        ]);

        Movement::factory()->create([
            'user_id' => $this->user->id,
            'tag_id' => $tag1->id,
            'amount' => 500,
            'type' => 'expense',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-28");

        $response->assertStatus(200);
        $response->assertJsonPath('data.shared_tags', []);
        $response->assertJsonPath('data.categories.0.is_shared', false);
        $response->assertJsonPath('data.categories.1.is_shared', false);
        $response->assertJsonPath('data.categories.0.spent', 500);
        $response->assertJsonPath('data.categories.1.spent', 0);
    }

    /**
     * Test: Category with mix of shared and exclusive tags.
     */
    public function test_mixed_shared_and_exclusive_tags(): void
    {
        $tagShared = Tag::factory()->for($this->user)->create(['name' => 'Comida']);
        $tagExclusive = Tag::factory()->for($this->user)->create(['name' => 'Mercado']);

        $budget = Budget::factory()->for($this->user)->create([
            'total_amount' => 4000,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        // Cat A: has both shared tag "Comida" + exclusive tag "Mercado"
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'name' => 'Alimentación',
            'amount' => 3000,
            'linked_tags' => ['Comida', 'Mercado'],
            'linked_tags_since' => now(),
            'order' => 0,
        ]);
        // Cat B: only shared tag "Comida"
        BudgetCategory::factory()->create([
            'budget_id' => $budget->id,
            'name' => 'Restaurantes',
            'amount' => 1000,
            'linked_tags' => ['Comida'],
            'linked_tags_since' => now(),
            'order' => 1,
        ]);

        // $800 with "Comida" (shared), $200 with "Mercado" (exclusive)
        Movement::factory()->create([
            'user_id' => $this->user->id,
            'tag_id' => $tagShared->id,
            'amount' => 800,
            'type' => 'expense',
        ]);
        Movement::factory()->create([
            'user_id' => $this->user->id,
            'tag_id' => $tagExclusive->id,
            'amount' => 200,
            'type' => 'expense',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/budgets/{$budget->id}/spending?from=2026-02-01&to=2026-02-28");

        $response->assertStatus(200);

        // "Comida" shared: $800 total → Alimentación 3000/(3000+1000) = 75% → $600, Restaurantes 25% → $200
        // "Mercado" exclusive for Alimentación → $200
        // Alimentación total = 600 + 200 = 800
        // Restaurantes total = 200
        // Grand total = 1000

        $response->assertJsonPath('data.total_spent', 1000);
        $response->assertJsonPath('data.categories.0.spent', 800);
        $response->assertJsonPath('data.categories.1.spent', 200);
        $response->assertJsonPath('data.shared_tags.0', 'Comida');
    }
}
