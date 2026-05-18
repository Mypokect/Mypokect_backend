<?php

namespace App\Services;

use App\Models\ScheduledTransaction;
use App\Models\TransactionOccurrence;
use Carbon\Carbon;

class OccurrenceCalculatorService
{
    /**
     * Generate and persist occurrences for the next $months months.
     * The exact time from start_date is preserved on every occurrence
     * (e.g. a 10:30 AM monthly transaction keeps 10:30 AM each month).
     * Uses upsert so existing paid occurrences are never overwritten.
     */
    public function generateAndStore(ScheduledTransaction $transaction, int $months = 13): int
    {
        $dates = $this->calculateDatetimes($transaction, $months);

        if (empty($dates)) {
            return 0;
        }

        $now  = now();
        $rows = array_map(fn ($dt) => [
            'scheduled_transaction_id' => $transaction->id,
            'due_date'                 => $dt,   // full datetime string
            'is_paid'                  => false,
            'created_at'               => $now,
            'updated_at'               => $now,
        ], $dates);

        // On conflict (scheduled_transaction_id + due_date) only touch
        // updated_at — is_paid is excluded so paid status survives regeneration.
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
            ->where('due_date', '>=', now())
            ->where('is_paid', false)
            ->delete();

        return $this->generateAndStore($transaction);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns an array of datetime strings for all occurrences within the
     * window [start_date … start_date + $months months].
     *
     * Time component of start_date is preserved in every occurrence:
     *   2026-01-15 10:30 → 2026-02-15 10:30 → 2026-03-15 10:30 …
     */
    private function calculateDatetimes(ScheduledTransaction $transaction, int $months): array
    {
        // Use the full datetime (no ->toDateString()) to keep the time.
        $start = Carbon::parse($transaction->start_date);
        $end   = $start->copy()->addMonths($months);

        // Honour the transaction's own end_date if it is earlier
        if ($transaction->end_date) {
            $txEnd = Carbon::parse($transaction->end_date);
            if ($txEnd->lessThan($end)) {
                $end = $txEnd;
            }
        }

        if ($transaction->recurrence_type === 'none') {
            return $start->lessThanOrEqualTo($end)
                ? [$start->format('Y-m-d H:i:s')]
                : [];
        }

        $interval = max(1, (int) $transaction->recurrence_interval);
        $datetimes = [];
        $current   = $start->copy();

        while ($current->lessThanOrEqualTo($end)) {
            $datetimes[] = $current->format('Y-m-d H:i:s');

            switch ($transaction->recurrence_type) {
                case 'daily':
                    $current->addDays($interval);
                    break;
                case 'weekly':
                    $current->addWeeks($interval);
                    break;
                case 'monthly':
                    // addMonthsNoOverflow keeps day-of-month stable (Jan 31 → Feb 28)
                    // while preserving the time-of-day component.
                    $current->addMonthsNoOverflow($interval);
                    break;
                case 'yearly':
                    $current->addYearsNoOverflow($interval);
                    break;
                default:
                    break 2;
            }
        }

        return $datetimes;
    }
}
