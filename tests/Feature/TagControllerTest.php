<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Test get all tags for authenticated user.
     */
    public function test_index_returns_all_user_tags(): void
    {
        $tags = Tag::factory()
            ->for($this->user)
            ->count(3)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/tags');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'name'],
            ],
        ]);

        $this->assertCount(3, $response['data']);
    }

    /**
     * Test index returns empty array when user has no tags.
     */
    public function test_index_returns_empty_when_no_tags(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/tags');

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'data']);
        $this->assertCount(0, $response['data']);
    }

    /**
     * Test index requires authentication.
     */
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/tags');

        $response->assertStatus(401);
    }

    /**
     * Test create tag with valid data.
     */
    public function test_store_creates_tag_with_valid_data(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/create', [
                'name' => 'Restaurante',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'tag' => ['id', 'name'],
        ]);

        $this->assertDatabaseHas('tags', [
            'user_id' => $this->user->id,
            'name' => 'Restaurante',
        ]);
    }

    /**
     * Test store normalizes tag name.
     */
    public function test_store_normalizes_tag_name(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/create', [
                'name' => 'COMIDA',
            ]);

        $response->assertStatus(201);

        // Note: Check what normalization is applied - ucfirst(strtolower()) converts to "Comida"
        $response->assertJsonStructure([
            'tag' => ['id', 'name'],
        ]);

        $this->assertEquals('Comida', $response['tag']['name']);
    }

    /**
     * Test store validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/create', [
                // Missing name
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('name', $response['messages']);
    }

    /**
     * Test store prevents duplicate tags for same user.
     */
    public function test_store_prevents_duplicate_tags(): void
    {
        Tag::factory()->for($this->user)->create(['name' => 'Comida']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/create', [
                'name' => 'Comida',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('name', $response['messages']);
    }

    /**
     * Test store allows same tag name for different users.
     */
    public function test_store_allows_same_tag_for_different_users(): void
    {
        Tag::factory()->for($this->user)->create(['name' => 'Comida']);

        $anotherUser = User::factory()->create();

        $response = $this->actingAs($anotherUser)
            ->postJson('/api/tags/create', [
                'name' => 'Comida',
            ]);

        $response->assertStatus(201);

        // Verify each user has their own tag
        $this->assertDatabaseHas('tags', [
            'user_id' => $this->user->id,
            'name' => 'Comida',
        ]);

        $this->assertDatabaseHas('tags', [
            'user_id' => $anotherUser->id,
            'name' => 'Comida',
        ]);
    }

    /**
     * Test store requires authentication.
     */
    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/tags/create', [
            'name' => 'Comida',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test suggest tag from description.
     */
    public function test_suggest_tag_success(): void
    {
        $this->user->tags()->create(['name' => 'Restaurante']);

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Restaurante',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/suggestion', [
                'descripcion' => 'Pizza dinner at local restaurant',
                'monto' => 50.00,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => ['tag'],
        ]);
    }

    /**
     * Test suggest tag validates required fields.
     */
    public function test_suggest_tag_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/suggestion', [
                // Missing descripcion and monto
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('descripcion', $response['messages']);
        $this->assertArrayHasKey('monto', $response['messages']);
    }

    /**
     * Test suggest tag validates amount format.
     */
    public function test_suggest_tag_validates_amount_format(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/suggestion', [
                'descripcion' => 'Test',
                'monto' => 'invalid',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
        $this->assertArrayHasKey('monto', $response['messages']);
    }

    /**
     * Test suggest tag requires authentication.
     */
    public function test_suggest_tag_requires_authentication(): void
    {
        $response = $this->postJson('/api/tags/suggestion', [
            'descripcion' => 'Test',
            'monto' => 50.00,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test suggest tag with empty user tags.
     */
    public function test_suggest_tag_with_no_existing_tags(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Transporte',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/suggestion', [
                'descripcion' => 'Taxi ride to airport',
                'monto' => 30.00,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'data' => ['tag']]);
    }

    /**
     * Test suggest tag handles API errors gracefully with fallback.
     */
    public function test_suggest_tag_handles_api_error(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['error' => 'API Error'], 500),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/suggestion', [
                'descripcion' => 'Test',
                'monto' => 50.00,
            ]);

        // Should return 200 with fallback tag 'Other' when API fails
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'data' => ['tag']]);
        $this->assertNotNull($response['data']['tag']);
    }

    /**
     * Test tag response structure.
     */
    public function test_tag_response_structure(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Comida']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/tags');

        $response->assertStatus(200);

        $responseTag = $response['data'][0];
        $this->assertArrayHasKey('id', $responseTag);
        $this->assertArrayHasKey('name', $responseTag);
        $this->assertEquals($tag->name, $responseTag['name']);
    }

    /**
     * Test suggest tag with various amounts.
     */
    public function test_suggest_tag_with_various_amounts(): void
    {
        $amounts = [0.01, 10, 100.50, 999999.99];

        foreach ($amounts as $amount) {
            Http::fake([
                'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Comida',
                            ],
                        ],
                    ],
                ], 200),
            ]);

            $response = $this->actingAs($this->user)
                ->postJson('/api/tags/suggestion', [
                    'descripcion' => 'Test description',
                    'monto' => $amount,
                ]);

            $response->assertStatus(200);
        }
    }

    /**
     * Test suggest tag returns valid tag name.
     */
    public function test_suggest_tag_returns_valid_tag_name(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Restaurante',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/tags/suggestion', [
                'descripcion' => 'Restaurant',
                'monto' => 50.00,
            ]);

        $response->assertStatus(200);

        $suggestedTag = $response['data']['tag'];
        $this->assertIsString($suggestedTag);
        $this->assertNotEmpty($suggestedTag);
    }

    /**
     * Test index does not return other users' tags.
     */
    public function test_index_does_not_return_other_users_tags(): void
    {
        $anotherUser = User::factory()->create();

        Tag::factory()->for($this->user)->create(['name' => 'UserTag']);
        Tag::factory()->for($anotherUser)->create(['name' => 'AnotherUserTag']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/tags');

        $response->assertStatus(200);
        $this->assertCount(1, $response['data']);
        $this->assertEquals('UserTag', $response['data'][0]['name']);
    }
}
