<?php

namespace App\Http\Controllers;

// Imports necesarios para que el controlador funcione
use App\Models\ScheduledTransaction;
use App\Models\TransactionOccurrence; // <-- ¡ESTE ERA EL IMPORT QUE FALTABA!
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class ScheduledTransactionController extends Controller
{
    /**
     * Devuelve todas las ocurrencias de transacciones para un mes/año,
     * con el estado de pago ('is_paid') verificado correctamente.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $month = $request->input('month');
        $year = $request->input('year');
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        // --- LÓGICA CORREGIDA Y EFICIENTE ---
        // 1. Obtenemos un mapa de todos los pagos ya realizados en este mes.
        // El formato será: ['2025-11-06' => [1 => true], '2025-11-15' => [2 => true]]
        // Significa: en la fecha X, la transacción con id Y está pagada.
        $paidOccurrencesMap = TransactionOccurrence::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurrence_date', [$startOfMonth, $endOfMonth])
            ->where('is_paid', true)
            ->get(['occurrence_date', 'scheduled_transaction_id'])
            ->groupBy(fn ($item) => $item->occurrence_date->format('Y-m-d'))
            ->map(fn ($itemsOnDate) => $itemsOnDate->keyBy('scheduled_transaction_id')->map(fn () => true))
            ->all();

        // 2. Obtenemos las transacciones que podrían tener ocurrencias
        $potentialTransactions = $user->scheduledTransactions()
            ->where('start_date', '<=', $endOfMonth)
            ->where(function ($query) use ($startOfMonth) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $startOfMonth);
            })->get();
        
        $occurrencesResult = [];

        // 3. Calculamos las ocurrencias en PHP
        foreach ($potentialTransactions as $transaction) {
            if ($transaction->recurrence_type === 'none') {
                $transactionDate = Carbon::parse($transaction->start_date);
                if ($transactionDate->between($startOfMonth, $endOfMonth)) {
                    // Verificamos en nuestro mapa si esta fecha/id está pagada
                    $paidStatus = $paidOccurrencesMap[$transactionDate->toDateString()][$transaction->id] ?? false;
                    $occurrencesResult[] = $this->createOccurrence($transaction, $transactionDate->toDateString(), $paidStatus);
                }
                continue;
            }

            $currentDate = Carbon::parse($transaction->start_date);
            while ($currentDate->lessThanOrEqualTo($endOfMonth)) {
                if ($currentDate->greaterThanOrEqualTo($startOfMonth)) {
                    // Verificamos en nuestro mapa si esta fecha/id está pagada
                    $paidStatus = $paidOccurrencesMap[$currentDate->toDateString()][$transaction->id] ?? false;
                    $occurrencesResult[] = $this->createOccurrence($transaction, $currentDate->toDateString(), $paidStatus);
                }
                
                // Lógica para avanzar la fecha (sin cambios)
                if ($currentDate->isBefore($startOfMonth)) {
                    $currentDate = $startOfMonth->copy()->day($transaction->start_date->day);
                    if ($currentDate->isBefore($startOfMonth)) { $currentDate->addMonth(); }
                } else {
                    switch ($transaction->recurrence_type) {
                        case 'daily': $currentDate->addDays($transaction->recurrence_interval); break;
                        case 'weekly': $currentDate->addWeeks($transaction->recurrence_interval); break;
                        case 'monthly': $currentDate->addMonthsNoOverflow($transaction->recurrence_interval); break;
                        case 'yearly': $currentDate->addYearsNoOverflow($transaction->recurrence_interval); break;
                        default: break 2;
                    }
                }
                if ($transaction->end_date && $currentDate->isAfter($transaction->end_date)) break;
            }
        }
        return response()->json($occurrencesResult);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255', 'amount' => 'required|numeric|min:0',
            'type' => 'required|string|in:expense,income', 'category' => 'nullable|string|max:100',
            'start_date' => 'required|date_format:Y-m-d',
            'recurrence_type' => 'required|string|in:none,daily,weekly,monthly,yearly',
            'recurrence_interval' => 'required_if:recurrence_type,!=,none|nullable|integer|min:1',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'reminder_days_before' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) { return response()->json(['errors' => $validator->errors()], 422); }
        $transaction = Auth::user()->scheduledTransactions()->create($validator->validated());
        return response()->json($transaction, 201);
    }

    public function togglePaidStatus(Request $request, ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $this->authorizeOwner($scheduledTransaction);
        $validator = Validator::make($request->all(), ['date' => 'required|date_format:Y-m-d', 'is_paid' => 'required|boolean',]);
        if ($validator->fails()) { return response()->json(['errors' => $validator->errors()], 422); }
        $validatedData = $validator->validated();
        
        $occurrence = $scheduledTransaction->occurrences()->updateOrCreate(
            [
                'scheduled_transaction_id' => $scheduledTransaction->id,
                'occurrence_date' => $validatedData['date'],
            ],
            [
                'is_paid' => $validatedData['is_paid'],
                'user_id' => Auth::id(),
            ]
        );
        return response()->json($occurrence);
    }

    public function show(ScheduledTransaction $scheduledTransaction): JsonResponse { $this->authorizeOwner($scheduledTransaction); return response()->json($scheduledTransaction); }
    public function update(Request $request, ScheduledTransaction $scheduledTransaction): JsonResponse { /* ... lógica de actualización ... */ }
    public function destroy(ScheduledTransaction $scheduledTransaction): JsonResponse { $this->authorizeOwner($scheduledTransaction); $scheduledTransaction->delete(); return response()->json(null, 204); }
    private function createOccurrence(ScheduledTransaction $transaction, string $date, bool $isPaid): array { return ['id' => $transaction->id, 'title' => $transaction->title, 'amount' => $transaction->amount, 'type' => $transaction->type, 'category' => $transaction->category, 'date' => $date, 'is_paid' => $isPaid,]; }
    private function authorizeOwner(ScheduledTransaction $transaction): void { if (Auth::id() !== $transaction->user_id) { abort(403, 'This action is unauthorized.'); } }
}