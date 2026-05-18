<?php

namespace App\Services;

use App\Models\ScheduledTransaction;
use App\Models\TransactionOccurrence;
use Carbon\Carbon;

class OccurrenceCalculatorService
{
    /**
     * Generate and persist occurrences for the next $months months.
     * Uses upsert so existing paid occurrences are never overwritten.
     */
    public function generateAndStore(ScheduledTransaction $transaction, int $months = 13): int
    {
        $dates = $this->calculateDates($transaction, $months);

        if (empty($dates)) {
            return 0;
        }

        $now  = now();
        $rows = array_map(fn ($date) => [
            'scheduled_transaction_id' => $transaction->id,
            'due_date'                 => $date,
            'is_paid'                  => false,
            'created_at'               => $now,
            'updated_at'               => $now,
        ], $dates);

        // upsert: on conflict (scheduled_transaction_id + due_date) only touch
        // updated_at — is_paid is intentionally excluded so paid status survives regeneration.
        TransactionOccurrence::upsert(
            $rows,
            ['scheduled_transaction_id', 'due_date'],
            ['updated_at']
        );

        return count($dates);
    }

    /**
     * Delete future pending occurrences then regenerate for the next 13 months.
     * Called when amount / recurrence / dates change on an existing transaction.
     */
    public function regeneratePending(ScheduledTransaction $transaction): int
    {
        TransactionOccurrence::where('scheduled_transaction_id', $transaction->id)
            ->where('due_date', '>=', today()->toDateString())
            ->where('is_paid', false)
            ->delete();

        return $this->generateAndStore($transaction);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function calculateDates(ScheduledTransaction $transaction, int $months): array
    {
        // Work with date-only Carbon instances (no time = no timezone shifts)
        $start = Carbon::parse($transaction->start_date->toDateString())->startOfDay();
        $end   = $start->copy()->addMonths($months)->startOfDay();

        // Honour the transaction's own end_date if it is earlier
        if ($transaction->end_date) {
            $txEnd = Carbon::parse($transaction->end_date->toDateString())->startOfDay();
            if ($txEnd->lessThan($end)) {
                $end = $txEnd;
            }
        }

        if ($transaction->recurrence_type === 'none') {
            return $start->lessThanOrEqualTo($end) ? [$start->toDateString()] : [];
        }

        $interval = max(1, (int) $transaction->recurrence_interval);
        $dates    = [];
        $current  = $start->copy();

        while ($current->lessThanOrEqualTo($end)) {
            $dates[] = $current->toDateString();

            switch ($transaction->recurrence_type) {
                case 'daily':
                    $current->addDays($interval);
                    break;
                case 'weekly':
                    $current->addWeeks($interval);
                    break;
                case 'monthly':
                    // addMonthsNoOverflow keeps day-of-month stable (e.g. Jan 31 → Feb 28)
                    $current->addMonthsNoOverflow($interval);
                    break;
                case 'yearly':
                    $current->addYearsNoOverflow($interval);
                    break;
                default:
                    break 2;
            }
        }

        return $dates;
    }
}
