<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use App\Services\MovementAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MovementAIServiceTest extends TestCase
{
    use RefreshDatabase;

    private MovementAIService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MovementAIService;
        $this->user = User::factory()->create();
    }

    /**
     * Test suggest from voice with mocked API.
     */
    public function test_suggest_from_voice_success(): void
    {
        $this->user->tags()->create(['name' => 'Comida']);

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
                                'payment_method' => 'tarjeta',
                                'has_invoice' => true,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $suggestion = $this->service->suggestFromVoice(
            'Bought groceries at the supermarket',
            $this->user
        );

        $this->assertIsArray($suggestion);
        $this->assertEquals('expense', $suggestion['type']);
        $this->assertEquals(35.50, $suggestion['amount']);
        $this->assertArrayHasKey('description', $suggestion);
        $this->assertArrayHasKey('suggested_tag', $suggestion);
        $this->assertArrayHasKey('payment_method', $suggestion);
        $this->assertArrayHasKey('has_invoice', $suggestion);
    }

    /**
     * Test suggest from voice with empty tags.
     */
    public function test_suggest_from_voice_with_empty_tags(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'income',
                                'amount' => 100.00,
                                'description' => 'Monthly salary',
                                'tag' => 'Salario',
                                'payment_method' => 'transferencia',
                                'has_invoice' => false,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $suggestion = $this->service->suggestFromVoice(
            'Received monthly salary payment',
            $this->user
        );

        $this->assertIsArray($suggestion);
        $this->assertEquals('income', $suggestion['type']);
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

        $suggestion = $this->service->suggestTag(
            'Pizza dinner at local restaurant',
            50.00,
            $this->user
        );

        $this->assertIsString($suggestion);
        $this->assertNotEmpty($suggestion);
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

        $suggestion = $this->service->suggestTag(
            'Taxi ride to airport',
            30.00,
            $this->user
        );

        $this->assertIsString($suggestion);
        $this->assertNotEmpty($suggestion);
    }

    /**
     * Test that logs are created during service calls.
     */
    public function test_logging_is_enabled_on_suggest_from_voice(): void
    {
        Log::spy();

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'expense',
                                'amount' => 25.00,
                                'description' => 'Food',
                                'tag' => 'Comida',
                                'payment_method' => 'tarjeta',
                                'has_invoice' => false,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->suggestFromVoice('Test movement', $this->user);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    /**
     * Test that logs are created during tag suggestion.
     */
    public function test_logging_is_enabled_on_suggest_tag(): void
    {
        Log::spy();

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

        $this->service->suggestTag('Restaurant dinner', 50, $this->user);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    /**
     * Test suggest from voice with expense type.
     */
    public function test_suggest_from_voice_with_expense_type(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'expense',
                                'amount' => 50.00,
                                'description' => 'Test transaction',
                                'tag' => 'Test',
                                'payment_method' => 'tarjeta',
                                'has_invoice' => false,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $suggestion = $this->service->suggestFromVoice(
            'Test expense transaction',
            $this->user
        );

        $this->assertEquals('expense', $suggestion['type']);
    }

    /**
     * Test suggest from voice with income type.
     */
    public function test_suggest_from_voice_with_income_type(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'income',
                                'amount' => 100.00,
                                'description' => 'Salary',
                                'tag' => 'Salary',
                                'payment_method' => 'transferencia',
                                'has_invoice' => false,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $suggestion = $this->service->suggestFromVoice(
            'Test income transaction',
            $this->user
        );

        $this->assertEquals('income', $suggestion['type']);
    }

    /**
     * Test suggest from voice response structure.
     */
    public function test_suggest_from_voice_response_structure(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'expense',
                                'amount' => 100.50,
                                'description' => 'Shopping at mall',
                                'tag' => 'Compras',
                                'payment_method' => 'tarjeta',
                                'has_invoice' => true,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->service->suggestFromVoice('Shopping at mall', $this->user);

        // Verify all required fields are present
        $requiredFields = ['type', 'amount', 'description', 'suggested_tag', 'payment_method', 'has_invoice'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $response, "Missing field: {$field}");
        }
    }

    /**
     * Test API error handling.
     */
    public function test_api_error_handling_groq_error(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 500),
        ]);

        $this->expectException(\Exception::class);

        $this->service->suggestFromVoice('Test', $this->user);
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

            $result = $this->service->suggestTag('Test description', $amount, $this->user);

            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        }
    }

    /**
     * Test suggest from voice preserves user context.
     */
    public function test_suggest_from_voice_uses_user_context(): void
    {
        // Create tags for the user
        $this->user->tags()->createMany([
            ['name' => 'Restaurante'],
            ['name' => 'Supermercado'],
            ['name' => 'Transporte'],
        ]);

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'type' => 'expense',
                                'amount' => 45.00,
                                'description' => 'Lunch at restaurant',
                                'tag' => 'Restaurante',
                                'payment_method' => 'tarjeta',
                                'has_invoice' => true,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $suggestion = $this->service->suggestFromVoice(
            'Had lunch at a nice restaurant',
            $this->user
        );

        // Verify the suggestion uses a tag from the user's context
        $userTags = $this->user->tags->pluck('name')->toArray();
        $this->assertContains($suggestion['suggested_tag'], $userTags);
    }

    /**
     * Test multiple sequential calls work correctly.
     */
    public function test_sequential_suggest_calls(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'type' => 'expense',
                                    'amount' => 25.00,
                                    'description' => 'Coffee',
                                    'tag' => 'Comida',
                                    'payment_method' => 'efectivo',
                                    'has_invoice' => false,
                                ]),
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'type' => 'expense',
                                    'amount' => 50.00,
                                    'description' => 'Lunch',
                                    'tag' => 'Comida',
                                    'payment_method' => 'tarjeta',
                                    'has_invoice' => true,
                                ]),
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $suggestion1 = $this->service->suggestFromVoice('Coffee break', $this->user);
        $suggestion2 = $this->service->suggestFromVoice('Lunch break', $this->user);

        $this->assertEquals(25.00, $suggestion1['amount']);
        $this->assertEquals(50.00, $suggestion2['amount']);
    }
}
