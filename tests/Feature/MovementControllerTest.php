<?php

namespace Tests\Feature;

use App\Models\Movement;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovementControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const VALID_PAYMENT_METHODS = ['cash', 'digital'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Test get all movements for authenticated user.
     */
    public function test_index_returns_all_user_movements(): void
    {
        // Create movements for the user
        $movements = Movement::factory()
            ->for($this->user)
            ->count(3)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/movements');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'type', 'amount', 'description', 'tag_name', 'payment_method', 'has_invoice', 'created_at'],
            ],
        ]);

        $this->assertCount(3, $response['data']);
    }

    /**
     * Test index returns empty array when user has no movements.
     */
    public function test_index_returns_empty_when_no_movements(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/movements');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertCount(0, $response['data']);
    }

    /**
     * Test index returns movements sorted by created_at descending.
     */
    public function test_index_returns_movements_sorted_by_date(): void
    {
        $movements = Movement::factory()
            ->for($this->user)
            ->count(3)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/movements');

        $response->assertStatus(200);

        // Verify movements are sorted by created_at descending
        $dates = array_map(fn ($m) => $m['created_at'], $response['data']);
        $this->assertEquals($dates, array_values($dates));
    }

    /**
     * Test index requires authentication.
     */
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/movements');

        $response->assertStatus(401);
    }

    /**
     * Test create movement with valid data.
     */
    public function test_store_creates_movement_with_valid_data(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/movements', [
                'type' => 'expense',
                'amount' => 50.00,
                'description' => 'Lunch at restaurant',
                'payment_method' => 'cash',
                'tag_name' => 'Comida',
                'has_invoice' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'data' => ['id', 'type', 'amount', 'description', 'tag_name', 'payment_method', 'has_invoice', 'created_at'],
        ]);

        $this->assertDatabaseHas('movements', [
            'type' => 'expense',
            'amount' => 50.00,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * Test store creates tag if it doesn't exist.
     */
    public function test_store_creates_tag_if_not_exists(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/movements', [
                'type' => 'expense',
                'amount' => 30.00,
                'description' => 'New category item',
                'payment_method' => 'cash',
                'tag_name' => 'NuevaCategoria',
                'has_invoice' => false,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('tags', [
            'user_id' => $this->user->id,
            'name' => 'Nuevacategoria',
        ]);
    }

    /**
     * Test store uses existing tag.
     */
    public function test_store_uses_existing_tag(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Comida']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/movements', [
                'type' => 'expense',
                'amount' => 25.00,
                'description' => 'Another meal',
                'payment_method' => 'digital',
                'tag_name' => 'Comida',
                'has_invoice' => false,
            ]);

        $response->assertStatus(201);

        // Verify no new tag was created
        $tagsCount = Tag::where('user_id', $this->user->id)
            ->where('name', 'Comida')
            ->count();

        $this->assertEquals(1, $tagsCount);
    }

    /**
     * Test store without tag_name creates movement without tag.
     */
    public function test_store_without_tag_name(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/movements', [
                'type' => 'income',
                'amount' => 1000.00,
                'description' => 'Salary payment',
                'payment_method' => 'digital',
                'has_invoice' => false,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('movements', [
            'type' => 'income',
            'amount' => 1000.00,
            'tag_id' => null,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * Test store validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/movements', [
                // Missing type, amount, payment_method
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('type', $response['messages']);
        $this->assertArrayHasKey('amount', $response['messages']);
        $this->assertArrayHasKey('payment_method', $response['messages']);
    }

    /**
     * Test store validates amount format.
     */
    public function test_store_validates_amount_format(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/movements', [
                'type' => 'expense',
                'amount' => 'invalid',
                'payment_method' => 'digital',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('amount', $response['messages']);
    }

    /**
     * Test store requires authentication.
     */
    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/movements', [
            'type' => 'expense',
            'amount' => 50.00,
            'payment_method' => 'tarjeta',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test suggest from voice with valid data.
     */
    public function test_suggest_from_voice_success(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'expense',
                                'amount' => 35.50,
                                'description' => 'Grocery shopping',
                                'tag' => 'Comida',
                                'payment_method' => 'digital',
                                'has_invoice' => true,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/movements/sugerir-voz', [
                'transcripcion' => 'Bought groceries at the supermarket',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'movement_suggestion' => [
                'type',
                'amount',
                'description',
                'suggested_tag',
                'payment_method',
                'has_invoice',
            ],
        ]);
    }

    /**
     * Test suggest from voice validates transcription.
     */
    public function test_suggest_from_voice_validates_transcription(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/movements/sugerir-voz', [
                // Missing transcripcion
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('transcripcion', $response['messages']);
    }

    /**
     * Test suggest from voice requires authentication.
     */
    public function test_suggest_from_voice_requires_authentication(): void
    {
        $response = $this->postJson('/api/movements/sugerir-voz', [
            'transcripcion' => 'Test transcription',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test suggest from voice handles API errors gracefully.
     */
    public function test_suggest_from_voice_handles_api_error(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 500),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/movements/sugerir-voz', [
                'transcripcion' => 'Test transcription',
            ]);

        $response->assertStatus(500);
        $response->assertJsonStructure(['error', 'message']);
    }

    /**
     * Test movement has correct tag_name in response.
     */
    public function test_movement_response_includes_tag_name(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Transporte']);

        $movement = Movement::factory()
            ->for($this->user)
            ->for($tag, 'tag')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/movements');

        $response->assertStatus(200);

        $movement = $response['data'][0];
        $this->assertEquals('Transporte', $movement['tag_name']);
    }

    /**
     * Test movement without tag has null tag_name.
     */
    public function test_movement_without_tag_has_null_tag_name(): void
    {
        Movement::factory()
            ->for($this->user)
            ->create(['tag_id' => null]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/movements');

        $response->assertStatus(200);

        $movement = $response['data'][0];
        $this->assertNull($movement['tag_name']);
    }

    /**
     * Test movement with all payment methods.
     */
    public function test_store_with_all_payment_methods(): void
    {
        $paymentMethods = ['cash', 'digital'];

        foreach ($paymentMethods as $method) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/movements', [
                    'type' => 'expense',
                    'amount' => 50.00,
                    'description' => 'Test movement',
                    'payment_method' => $method,
                    'has_invoice' => false,
                ]);

            $response->assertStatus(201);
            $this->assertDatabaseHas('movements', [
                'payment_method' => $method,
                'user_id' => $this->user->id,
            ]);
        }
    }
}
