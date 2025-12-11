<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReminderRequest;
use App\Http\Requests\UpdateReminderRequest;
use App\Http\Resources\ReminderResource;
use App\Http\Resources\ReminderCollection;
use App\Models\Reminder;
use App\Services\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReminderController extends Controller
{
    public function __construct(
        private RecurrenceService $recurrenceService
    ) {}

    /**
     * Display a listing of reminders within a date range.
     * 
     * @param Request $request
     * @return ReminderCollection
     */
    public function index(Request $request): ReminderCollection
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'status' => 'sometimes|in:pending,paid,all',
        ]);

        $start = Carbon::parse($request->start);
        $end = Carbon::parse($request->end);
        $status = $request->input('status', 'all');

        $query = Reminder::where('user_id', $request->user()->id)
            ->dateRange($start, $end);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $reminders = $query->orderBy('due_date', 'asc')->get();

        // For monthly recurring reminders, generate occurrences within range
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

                    // Create virtual reminder instances for each occurrence
                    foreach ($occurrences as $occurrenceDate) {
                        $virtualReminder = clone $reminder;
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
     * Store a newly created reminder.
     * 
     * @param StoreReminderRequest $request
     * @return JsonResponse
     */
    public function store(StoreReminderRequest $request): JsonResponse
    {
        Gate::authorize('create', Reminder::class);

        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        // Convert due_date to UTC
        $dueDate = Carbon::parse($data['due_date'], $data['timezone']);
        $data['due_date'] = $dueDate->setTimezone('UTC');

        $reminder = Reminder::create($data);

        return response()->json([
            'data' => new ReminderResource($reminder),
            'message' => 'Recordatorio creado exitosamente.',
        ], 201);
    }

    /**
     * Display the specified reminder.
     * 
     * @param Reminder $reminder
     * @return ReminderResource
     */
    public function show(Reminder $reminder): ReminderResource
    {
        Gate::authorize('view', $reminder);

        return new ReminderResource($reminder);
    }

    /**
     * Update the specified reminder.
     * 
     * @param UpdateReminderRequest $request
     * @param Reminder $reminder
     * @return JsonResponse
     */
    public function update(UpdateReminderRequest $request, Reminder $reminder): JsonResponse
    {
        Gate::authorize('update', $reminder);

        $data = $request->validated();

        // Convert due_date to UTC if provided
        if (isset($data['due_date'])) {
            $timezone = $data['timezone'] ?? $reminder->timezone;
            $dueDate = Carbon::parse($data['due_date'], $timezone);
            $data['due_date'] = $dueDate->setTimezone('UTC');
        }

        $reminder->update($data);

        return response()->json([
            'data' => new ReminderResource($reminder->fresh()),
            'message' => 'Recordatorio actualizado exitosamente.',
        ]);
    }

    /**
     * Remove the specified reminder.
     * 
     * @param Reminder $reminder
     * @return JsonResponse
     */
    public function destroy(Reminder $reminder): JsonResponse
    {
        Gate::authorize('delete', $reminder);

        $reminder->delete();

        return response()->json([
            'message' => 'Recordatorio eliminado exitosamente.',
        ]);
    }

    /**
     * Mark a reminder as paid.
     * 
     * @param Request $request
     * @param Reminder $reminder
     * @return JsonResponse
     */
    public function markAsPaid(Request $request, Reminder $reminder): JsonResponse
    {
        Gate::authorize('update', $reminder);

        $request->validate([
            'occurrence_date' => 'sometimes|date',
            'amount_paid' => 'sometimes|numeric|min:0',
            'note' => 'sometimes|string|max:1000',
        ]);

        $reminder->update(['status' => 'paid']);

        // Create payment record if amount provided
        if ($request->has('amount_paid')) {
            $reminder->payments()->create([
                'paid_at' => now(),
                'amount_paid' => $request->amount_paid,
                'note' => $request->note,
            ]);
        }

        return response()->json([
            'data' => new ReminderResource($reminder->fresh()),
            'message' => 'Recordatorio marcado como pagado.',
        ]);
    }
}
