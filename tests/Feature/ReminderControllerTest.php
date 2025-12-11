<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_create_a_reminder()
    {
        $data = [
            'title' => 'Pago tarjeta de crédito',
            'amount' => 500000,
            'category' => 'Tarjetas',
            'note' => 'Pago mensual',
            'due_date' => now()->addDays(5)->toIso8601String(),
            'timezone' => 'America/Bogota',
            'recurrence' => 'none',
            'notify_offset_minutes' => 1440,
            'status' => 'pending',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/calendar/reminders', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'amount',
                    'due_date',
                    'status',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('reminders', [
            'user_id' => $this->user->id,
            'title' => 'Pago tarjeta de crédito',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_can_create_a_monthly_recurring_reminder()
    {
        $data = [
            'title' => 'Arriendo',
            'amount' => 1200000,
            'category' => 'Vivienda',
            'due_date' => now()->addMonth()->toIso8601String(),
            'timezone' => 'America/Bogota',
            'recurrence' => 'monthly',
            'recurrence_params' => ['dayOfMonth' => 5],
            'notify_offset_minutes' => 2880,
            'status' => 'pending',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/calendar/reminders', $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('reminders', [
            'user_id' => $this->user->id,
            'recurrence' => 'monthly',
        ]);

        $reminder = Reminder::where('user_id', $this->user->id)->first();
        $this->assertEquals(5, $reminder->recurrence_params['dayOfMonth']);
    }

    /** @test */
    public function it_can_list_reminders_in_date_range()
    {
        // Create reminders
        Reminder::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Reminder 1',
            'due_date' => now()->addDays(2),
        ]);

        Reminder::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Reminder 2',
            'due_date' => now()->addDays(10),
        ]);

        Reminder::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Reminder 3',
            'due_date' => now()->addDays(20),
        ]);

        $start = now()->startOfDay()->toIso8601String();
        $end = now()->addDays(15)->endOfDay()->toIso8601String();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/calendar/reminders?start={$start}&end={$end}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_can_update_a_reminder()
    {
        $reminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Original Title',
        ]);

        $data = [
            'title' => 'Updated Title',
            'amount' => 750000,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/calendar/reminders/{$reminder->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('reminders', [
            'id' => $reminder->id,
            'title' => 'Updated Title',
            'amount' => 750000,
        ]);
    }

    /** @test */
    public function it_can_mark_reminder_as_paid()
    {
        $reminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/calendar/reminders/{$reminder->id}/mark-paid", [
                'amount_paid' => 500000,
                'note' => 'Pagado completamente',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('reminders', [
            'id' => $reminder->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'reminder_id' => $reminder->id,
            'amount_paid' => 500000,
        ]);
    }

    /** @test */
    public function it_can_delete_a_reminder()
    {
        $reminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/calendar/reminders/{$reminder->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('reminders', [
            'id' => $reminder->id,
        ]);
    }

    /** @test */
    public function user_cannot_access_other_users_reminders()
    {
        $otherUser = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/calendar/reminders/{$reminder->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function it_validates_required_fields()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/calendar/reminders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'due_date', 'timezone', 'recurrence', 'notify_offset_minutes']);
    }
}
