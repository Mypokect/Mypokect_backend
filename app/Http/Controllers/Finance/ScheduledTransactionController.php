<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ScheduledTransaction;
use App\Models\TransactionOccurrence;
use App\Services\OccurrenceCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ScheduledTransactionController extends Controller
{
    use ApiResponse;

    // ─── index: calendar view for a month ────────────────────────────────────

    /**
     * @queryParam month int required Month 1-12. Example: 3
     * @queryParam year  int required Year.       Example: 2026
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user         = Auth::user();
        $month        = (int) $request->month;
        $year         = (int) $request->year;
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();

        // Build a quick-lookup map: stid+date → is_paid
        $paidMap = TransactionOccurrence::query()
            ->whereHas('scheduledTransaction', fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
            ->where('is_paid', true)
            ->get(['due_date', 'scheduled_transaction_id'])
            ->groupBy(fn ($item) => $item->due_date->format('Y-m-d'))
            ->map(fn ($g) => $g->keyBy('scheduled_transaction_id')->map(fn () => true))
            ->all();

        // Active transactions that overlap with this month
        $transactions = $user->scheduledTransactions()
            ->where('start_date', '<=', $endOfMonth)
            ->where(function ($q) use ($startOfMonth) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $startOfMonth);
            })->get();

        $result = [];

        foreach ($transactions as $tx) {
            if ($tx->recurrence_type === 'none') {
                // Preserve full datetime (not just date) so time is included.
                $d = Carbon::parse($tx->start_date);
                if ($d->between($startOfMonth, $endOfMonth)) {
                    $result[] = $this->buildEvent(
                        $tx,
                        $d->toIso8601String(),
                        $paidMap[$d->toDateString()][$tx->id] ?? false
                    );
                }
                continue;
            }

            $current  = Carbon::parse($tx->start_date); // full datetime
            $interval = max(1, (int) $tx->recurrence_interval);

            // Fast-forward to the first occurrence inside (or just before) the month
            if ($current->lessThan($startOfMonth)) {
                $current = $this->fastForwardToMonth($tx, $current, $startOfMonth);
            }

            while ($current->lessThanOrEqualTo($endOfMonth)) {
                if ($current->greaterThanOrEqualTo($startOfMonth)) {
                    $result[] = $this->buildEvent(
                        $tx,
                        $current->toIso8601String(),
                        $paidMap[$current->toDateString()][$tx->id] ?? false
                    );
                }

                switch ($tx->recurrence_type) {
                    case 'daily':   $current->addDays($interval);              break;
                    case 'weekly':  $current->addWeeks($interval);             break;
                    case 'monthly': $current->addMonthsNoOverflow($interval);  break;
                    case 'yearly':  $current->addYearsNoOverflow($interval);   break;
                    default: break 2;
                }

                if ($tx->end_date && $current->isAfter(Carbon::parse($tx->end_date))) {
                    break;
                }
            }
        }

        return $this->successResponse($result);
    }

    // ─── getEvents: range-based view (reads persisted occurrences) ────────────

    /**
     * @queryParam start string required Start date Y-m-d. Example: 2026-05-01
     * @queryParam end   string required End   date Y-m-d. Example: 2026-05-31
     */
    public function getEvents(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|date_format:Y-m-d',
            'end'   => 'required|date_format:Y-m-d|after_or_equal:start',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user  = Auth::user();
        $start = Carbon::parse($request->start)->startOfDay();
        $end   = Carbon::parse($request->end)->endOfDay();

        $occurrences = TransactionOccurrence::query()
            ->join('scheduled_transactions as st', 'st.id', '=', 'transaction_occurrences.scheduled_transaction_id')
            ->where('st.user_id', $user->id)
            ->whereBetween('transaction_occurrences.due_date', [$start, $end])
            ->orderBy('transaction_occurrences.due_date', 'asc')
            ->select([
                'transaction_occurrences.id',
                'transaction_occurrences.scheduled_transaction_id',
                'transaction_occurrences.due_date',
                'transaction_occurrences.is_paid',
                'st.title',
                'st.amount',
                'st.type',
                'st.category',
            ])
            ->get();

        $events = $occurrences->map(fn ($o) => [
            'id'                       => $o->id,
            'scheduled_transaction_id' => $o->scheduled_transaction_id,
            // Return full ISO-8601 datetime so Flutter can display the time.
            'datetime'                 => Carbon::parse($o->due_date)->toIso8601String(),
            'date'                     => Carbon::parse($o->due_date)->toDateString(),
            'title'                    => $o->title,
            'amount'                   => (float) $o->amount,
            'type'                     => $o->type,
            'category'                 => $o->category,
            'is_paid'                  => (bool) $o->is_paid,
            'color'                    => $o->type === 'income' ? 'green' : 'red',
        ])->values();

        return $this->successResponse($events);
    }

    // ─── store ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title'                => 'required|string|max:255',
            'amount'               => 'required|numeric|min:0',
            'type'                 => 'required|string|in:expense,income',
            'category'             => 'nullable|string|max:100',
            'start_date'           => 'required|date',
            'recurrence_type'      => 'required|string|in:none,daily,weekly,monthly,yearly',
            'recurrence_interval'  => 'required_if:recurrence_type,!=,none|nullable|integer|min:1',
            'end_date'             => 'nullable|date|after_or_equal:start_date',
            'reminder_days_before' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $transaction = Auth::user()->scheduledTransactions()->create($validator->validated());

        $service = app(OccurrenceCalculatorService::class);
        $count   = $service->generateAndStore($transaction);

        return $this->createdResponse([
            'status'                => 'success',
            'data'                  => $transaction->fresh(),
            'occurrences_generated' => $count,
        ]);
    }

    // ─── show ─────────────────────────────────────────────────────────────────

    public function show(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $this->authorizeOwner($scheduledTransaction);

        return $this->successResponse($scheduledTransaction->load('occurrences'));
    }

    // ─── update ───────────────────────────────────────────────────────────────

    public function update(Request $request, ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $this->authorizeOwner($scheduledTransaction);

        $validator = Validator::make($request->all(), [
            'title'                => 'sometimes|string|max:255',
            'amount'               => 'sometimes|numeric|min:0',
            'type'                 => 'sometimes|string|in:expense,income',
            'category'             => 'nullable|string|max:100',
            'start_date'           => 'sometimes|date',
            'recurrence_type'      => 'sometimes|string|in:none,daily,weekly,monthly,yearly',
            'recurrence_interval'  => 'nullable|integer|min:1',
            'end_date'             => 'nullable|date|after_or_equal:start_date',
            'reminder_days_before' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $recurrenceFields = ['start_date', 'end_date', 'recurrence_type', 'recurrence_interval', 'amount'];
        $needsRegenerate  = collect($validator->validated())
            ->keys()
            ->intersect($recurrenceFields)
            ->isNotEmpty();

        $scheduledTransaction->update($validator->validated());

        $service = app(OccurrenceCalculatorService::class);
        if ($needsRegenerate) {
            $service->regeneratePending($scheduledTransaction->fresh());
        }

        return $this->successResponse([
            'status' => 'success',
            'data'   => $scheduledTransaction->fresh()->load('occurrences'),
        ]);
    }

    // ─── destroy ──────────────────────────────────────────────────────────────

    public function destroy(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $this->authorizeOwner($scheduledTransaction);

        // Delete pending occurrences explicitly (FK cascade also handles this,
        // but being explicit ensures future calendar dots disappear immediately).
        $scheduledTransaction->occurrences()->where('is_paid', false)->delete();
        $scheduledTransaction->delete();

        return $this->successResponse(null, 'Recordatorio eliminado y calendario limpio');
    }

    // ─── togglePaidStatus ─────────────────────────────────────────────────────

    public function togglePaidStatus(Request $request, ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $this->authorizeOwner($scheduledTransaction);

        $validator = Validator::make($request->all(), [
            'date'    => 'required|date_format:Y-m-d',
            'is_paid' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $occurrence = $scheduledTransaction->occurrences()->updateOrCreate(
            [
                'scheduled_transaction_id' => $scheduledTransaction->id,
                'due_date'                 => $validator->validated()['date'],
            ],
            ['is_paid' => $validator->validated()['is_paid']]
        );

        return $this->successResponse([
            'status' => 'success',
            'data'   => $occurrence,
        ]);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function buildEvent(ScheduledTransaction $tx, string $date, bool $isPaid): array
    {
        return [
            'id'       => $tx->id,
            'title'    => $tx->title,
            'amount'   => (float) $tx->amount,
            'type'     => $tx->type,
            'category' => $tx->category,
            'date'     => $date,
            'is_paid'  => $isPaid,
            'color'    => $tx->type === 'income' ? 'green' : 'red',
        ];
    }

    /**
     * Fast-forward $current to the first occurrence >= $targetMonth,
     * without iterating month by month from start_date.
     */
    private function fastForwardToMonth(ScheduledTransaction $tx, Carbon $current, Carbon $targetMonth): Carbon
    {
        $interval = max(1, (int) $tx->recurrence_interval);

        switch ($tx->recurrence_type) {
            case 'daily':
                $days = (int) ceil($current->diffInDays($targetMonth, false));
                if ($days > 0) {
                    $steps = (int) ceil($days / $interval);
                    $current->addDays($steps * $interval);
                }
                break;

            case 'weekly':
                $weeks = (int) ceil($current->diffInWeeks($targetMonth, false));
                if ($weeks > 0) {
                    $steps = (int) ceil($weeks / $interval);
                    $current->addWeeks($steps * $interval);
                }
                break;

            case 'monthly':
                $months = (int) ceil($current->diffInMonths($targetMonth, false));
                if ($months > 0) {
                    $steps = (int) ceil($months / $interval);
                    $current->addMonthsNoOverflow($steps * $interval);
                }
                // Step back one interval if we overshot
                while ($current->greaterThan($targetMonth)) {
                    $current->subMonthsNoOverflow($interval);
                }
                // Step forward until we are >= targetMonth
                while ($current->lessThan($targetMonth)) {
                    $current->addMonthsNoOverflow($interval);
                }
                break;

            case 'yearly':
                while ($current->lessThan($targetMonth)) {
                    $current->addYearsNoOverflow($interval);
                }
                break;
        }

        return $current;
    }

    private function authorizeOwner(ScheduledTransaction $transaction): void
    {
        if (Auth::id() !== $transaction->user_id) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
