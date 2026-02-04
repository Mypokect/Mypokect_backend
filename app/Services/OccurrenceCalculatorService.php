<?php

namespace App\Services;

use App\Models\ScheduledTransaction;
use App\Models\TransactionOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class OccurrenceCalculatorService
{
    public function getOccurrencesForMonth(int $month, int $year): array
    {
        $user = Auth::user();
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        // 1. Obtenemos TODAS las ocurrencias que ya han sido marcadas como pagadas en este mes.
        // Las guardamos en un formato fácil de buscar: ['2025-11-04' => true, '2025-11-15' => true]
        $paidStatuses = TransactionOccurrence::query()
            ->where('user_id', $user->id) // Asumiendo que añades user_id a la tabla de ocurrencias
            // O si no tienes user_id, se puede hacer con un join:
            // ->whereHas('scheduledTransaction', fn($q) => $q->where('user_id', $user->id))
            ->whereBetween('occurrence_date', [$startOfMonth, $endOfMonth])
            ->where('is_paid', true)
            ->pluck('occurrence_date')
            ->mapWithKeys(fn ($date) => [$date->format('Y-m-d') => true])
            ->all();

        // 2. Obtenemos las transacciones que podrían tener ocurrencias en este mes.
        $potentialTransactions = $user->scheduledTransactions()
            ->where('start_date', '<=', $endOfMonth)
            ->where(function ($query) use ($startOfMonth) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $startOfMonth);
            })->get();

        $occurrencesResult = [];

        // 3. Calculamos las ocurrencias en PHP, igual que antes.
        foreach ($potentialTransactions as $transaction) {
            // Lógica de cálculo (no cambia) ...
            if ($transaction->recurrence_type === 'none') { /* ... */
            }
            // ... resto de tu lógica de while ...

            // 4. CORRECCIÓN CLAVE: Al momento de crear la ocurrencia, buscamos en nuestro array de pagos.
            // Es mucho más rápido y fiable que buscar en la colección de Eloquent.
            $isPaid = $paidStatuses[$currentDate->toDateString()] ?? false;

            $occurrencesResult[] = $this->formatOccurrence($transaction, $currentDate->toDateString(), $isPaid);
        }

        return $occurrencesResult;
    }

    // El método formatOccurrence no cambia.
    private function formatOccurrence(ScheduledTransaction $transaction, string $date, bool $isPaid): array
    {
        return [
            'id' => $transaction->id, 'title' => $transaction->title, 'amount' => $transaction->amount,
            'type' => $transaction->type, 'category' => $transaction->category,
            'date' => $date, 'is_paid' => $isPaid,
        ];
    }
}
