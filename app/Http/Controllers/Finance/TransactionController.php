<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\GoalContribution;
use App\Models\Movement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * Get unified view of all transactions (movements + goal contributions).
     * GET /api/transactions/unified
     *
     * Query parameters:
     * - type: expense,income,contribution (comma-separated)
     * - start_date: YYYY-MM-DD
     * - end_date: YYYY-MM-DD
     * - goal_id: Filter by specific goal
     * - page: 1 (default)
     * - per_page: 50 (default, configurable: 10, 50, 100)
     */
    public function unified(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (! $user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            Log::info('Fetching unified transactions', [
                'user_id' => $user->id,
                'filters' => $request->all(),
            ]);

            // Parse filters
            $types = $request->query('type') ? explode(',', $request->query('type')) : ['expense', 'income', 'contribution'];
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $goalId = $request->query('goal_id');
            $perPage = min($request->query('per_page', 50), 100);
            $page = $request->query('page', 1);

            // Validate per_page
            if (! in_array($perPage, [10, 50, 100])) {
                $perPage = 50;
            }

            $transactions = [];

            // Fetch movements if needed
            if (in_array('expense', $types) || in_array('income', $types)) {
                $movementTypes = [];
                if (in_array('expense', $types)) {
                    $movementTypes[] = 'expense';
                }
                if (in_array('income', $types)) {
                    $movementTypes[] = 'income';
                }

                $movementQuery = Movement::where('user_id', $user->id)
                    ->whereIn('type', $movementTypes);

                if ($startDate) {
                    $movementQuery->whereDate('created_at', '>=', $startDate);
                }
                if ($endDate) {
                    $movementQuery->whereDate('created_at', '<=', $endDate);
                }

                $movements = $movementQuery->get();

                foreach ($movements as $movement) {
                    $transactions[] = $this->transformMovement($movement);
                }
            }

            // Fetch goal contributions if needed
            if (in_array('contribution', $types)) {
                $contributionQuery = GoalContribution::where('user_id', $user->id);

                if ($goalId) {
                    $contributionQuery->where('goal_id', $goalId);
                }

                if ($startDate) {
                    $contributionQuery->whereDate('created_at', '>=', $startDate);
                }
                if ($endDate) {
                    $contributionQuery->whereDate('created_at', '<=', $endDate);
                }

                $contributions = $contributionQuery->get();

                foreach ($contributions as $contribution) {
                    $transactions[] = $this->transformContribution($contribution);
                }
            }

            // Sort by date (ASC - oldest first) as per requirement
            usort($transactions, function ($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
            });

            // Paginate
            $total = count($transactions);
            $paginated = array_slice($transactions, ($page - 1) * $perPage, $perPage);
            $lastPage = ceil($total / $perPage);

            Log::info('Unified transactions fetched', [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ]);

            return response()->json([
                'data' => $paginated,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => (($page - 1) * $perPage) + 1,
                    'to' => min($page * $perPage, $total),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching unified transactions: '.$e->getMessage());

            return response()->json([
                'error' => 'Error fetching transactions',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transform Movement to unified transaction format.
     */
    private function transformMovement(Movement $movement): array
    {
        return [
            'id' => "m_{$movement->id}",
            'type' => $movement->type,
            'type_badge' => $movement->type === 'expense' ? 'Gasto' : 'Ingreso',
            'amount' => (float) $movement->amount,
            'description' => $movement->description,
            'category' => $movement->tag?->name,
            'goal_name' => null,
            'date' => $movement->created_at->toIso8601String(),
            'payment_method' => $movement->payment_method,
            'source' => 'movement',
        ];
    }

    /**
     * Transform GoalContribution to unified transaction format.
     */
    private function transformContribution(GoalContribution $contribution): array
    {
        return [
            'id' => "gc_{$contribution->id}",
            'type' => 'contribution',
            'type_badge' => 'Abono',
            'amount' => (float) $contribution->amount,
            'description' => $contribution->description,
            'category' => null,
            'goal_name' => $contribution->goal?->name,
            'date' => $contribution->created_at->toIso8601String(),
            'payment_method' => null,
            'source' => 'goal_contribution',
        ];
    }
}
