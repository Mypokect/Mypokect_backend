<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReminderRequest;
use App\Http\Requests\UpdateReminderRequest;
use App\Http\Resources\ReminderCollection;
use App\Http\Resources\ReminderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Reminder;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReminderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RecurrenceService $recurrenceService
    ) {}

    /**
     * List reminders in a date range.
     *
     * Returns reminders within the given range, expanding monthly recurring ones into individual occurrences.
     *
     * @queryParam start string required Start date. Example: 2026-03-01
     * @queryParam end string required End date. Example: 2026-03-31
     * @queryParam status string optional Filter: pending, paid, all. Example: all
     */
    public function index(Request $request): ReminderCollection
    {
        $request->validate([
            'start'  => 'required|date',
            'end'    => 'required|date|after_or_equal:start',
            'status' => 'sometimes|in:pending,paid,all',
        ]);

        $start  = Carbon::parse($request->start);
        $end    = Carbon::parse($request->end);
        $status = $request->input('status', 'all');

        $query = Reminder::where('user_id', $request->user()->id)->dateRange($start, $end);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $reminders         = $query->orderBy('due_date', 'asc')->get();
        $expandedReminders = collect();

        foreach ($reminders as $reminder) {
            if ($reminder->isMonthlyRecurring() && $reminder->recurrence_params) {
                $dayOfMonth = $reminder->recurrence_params['dayOfMonth'] ?? null;

                if ($dayOfMonth) {
                    $occurrences = $this->recurrenceService->getOccurrencesInRange(
                        $reminder->due_date,
                        $dayOfMonth,
                        $reminder->timezone,
                        $start,
                        $end
                    );

                    foreach ($occurrences as $occurrenceDate) {
                        $virtualReminder           = clone $reminder;
                        $virtualReminder->due_date = $occurrenceDate;
                        $expandedReminders->push($virtualReminder);
                    }
                } else {
                    $expandedReminders->push($reminder);
                }
            } else {
                $expandedReminders->push($reminder);
            }
        }

        return new ReminderCollection($expandedReminders->sortBy('due_date')->values());
    }

    /**
     * Create a reminder.
     *
     * Creates a payment/bill reminder. Due date is converted to UTC using the provided timezone.
     */
    public function store(StoreReminderRequest $request): JsonResponse
    {
        Gate::authorize('create', Reminder::class);

        $data              = $request->validated();
        $data['user_id']   = $request->user()->id;

        $dueDate           = Carbon::parse($data['due_date'], $data['timezone']);
        $data['due_date']  = $dueDate->setTimezone('UTC');

        $reminder = Reminder::create($data);

        return $this->createdResponse(new ReminderResource($reminder), 'Recordatorio creado exitosamente.');
    }

    /**
     * Get a reminder.
     */
    public function show(Reminder $reminder): ReminderResource
    {
        Gate::authorize('view', $reminder);

        return new ReminderResource($reminder);
    }

    /**
     * Update a reminder.
     */
    public function update(UpdateReminderRequest $request, Reminder $reminder): JsonResponse
    {
        Gate::authorize('update', $reminder);

        $data = $request->validated();

        if (isset($data['due_date'])) {
            $timezone         = $data['timezone'] ?? $reminder->timezone;
            $dueDate          = Carbon::parse($data['due_date'], $timezone);
            $data['due_date'] = $dueDate->setTimezone('UTC');
        }

        $reminder->update($data);

        return $this->successResponse(new ReminderResource($reminder->fresh()), 'Recordatorio actualizado exitosamente.');
    }

    /**
     * Delete a reminder.
     */
    public function destroy(Reminder $reminder): JsonResponse
    {
        Gate::authorize('delete', $reminder);

        $reminder->delete();

        return $this->deletedResponse('Recordatorio eliminado exitosamente.');
    }

    /**
     * Mark a reminder as paid.
     *
     * @bodyParam occurrence_date string optional Date of the occurrence. Example: 2026-03-15
     * @bodyParam amount_paid number optional Amount paid. Example: 150000
     * @bodyParam note string optional Payment note. Example: Pagado por transferencia
     */
    public function markAsPaid(Request $request, Reminder $reminder): JsonResponse
    {
        Gate::authorize('update', $reminder);

        $request->validate([
            'occurrence_date' => 'sometimes|date',
            'amount_paid'     => 'sometimes|numeric|min:0',
            'note'            => 'sometimes|string|max:1000',
        ]);

        $reminder->update(['status' => 'paid']);

        if ($request->has('amount_paid')) {
            $reminder->payments()->create([
                'paid_at'     => now(),
                'amount_paid' => $request->amount_paid,
                'note'        => $request->note,
            ]);
        }

        return $this->successResponse(new ReminderResource($reminder->fresh()), 'Recordatorio marcado como pagado.');
    }
}
