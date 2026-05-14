<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GoalContribution;
use App\Models\Movement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    use ApiResponse;

    /**
     * Get unified transactions.
     *
     * Combines movements and goal contributions into a single paginated timeline.
     *
     * @queryParam type string optional Comma-separated types: expense, income, contribution. Example: expense,income
     * @queryParam start_date string optional Start date (Y-m-d). Example: 2026-03-01
     * @queryParam end_date string optional End date (Y-m-d). Example: 2026-03-31
     * @queryParam goal_id int optional Filter contributions by goal ID. Example: 5
     * @queryParam page int optional Page number. Example: 1
     * @queryParam per_page int optional Items per page (10, 50, 100). Example: 50
     */
    public function unified(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info('Fetching unified transactions', [
                'user_id' => $user->id,
                'filters' => $request->all(),
            ]);

            $types     = $request->query('type') ? explode(',', $request->query('type')) : ['expense', 'income', 'contribution'];
            $startDate = $request->query('start_date');
            $endDate   = $request->query('end_date');
            $goalId    = $request->query('goal_id');
            $perPage   = (int) $request->query('per_page', 50);
            $page      = (int) $request->query('page', 1);

            if (! in_array($perPage, [10, 50, 100])) {
                $perPage = 50;
            }

            $transactions = [];

            // ── Movements ────────────────────────────────────────────────────
            if (in_array('expense', $types) || in_array('income', $types)) {
                $movementTypes = array_intersect(['expense', 'income'], $types);

                $query = Movement::where('user_id', $user->id)
                    ->whereIn('type', $movementTypes)
                    ->with('tag');

                if ($startDate) {
                    $query->whereDate('created_at', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('created_at', '<=', $endDate);
                }

                foreach ($query->get() as $movement) {
                    $transactions[] = $this->transformMovement($movement);
                }
            }

            // ── Goal Contributions ────────────────────────────────────────────
            if (in_array('contribution', $types)) {
                $query = GoalContribution::where('user_id', $user->id)->with('goal');

                if ($goalId) {
                    $query->where('goal_id', (int) $goalId);
                }
                if ($startDate) {
                    $query->whereDate('created_at', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('created_at', '<=', $endDate);
                }

                foreach ($query->get() as $contribution) {
                    $transactions[] = $this->transformContribution($contribution);
                }
            }

            // Sort oldest → newest
            usort($transactions, fn ($a, $b) => strtotime($a['date']) - strtotime($b['date']));

            $total    = count($transactions);
            $paginated = array_slice($transactions, ($page - 1) * $perPage, $perPage);
            $lastPage = (int) ceil($total / max(1, $perPage));

            Log::info('Unified transactions fetched', [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ]);

            return $this->successResponse([
                'data'       => $paginated,
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'from'         => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
                    'to'           => min($page * $perPage, $total),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching unified transactions: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    private function transformMovement(Movement $movement): array
    {
        return [
            'id'             => "m_{$movement->id}",
            'type'           => $movement->type,
            'type_badge'     => $movement->type === 'expense' ? 'Gasto' : 'Ingreso',
            'amount'         => (float) ($movement->amount ?? 0),
            'description'    => $movement->description ?? '',
            'category'       => $movement->tag?->name,
            'goal_name'      => null,
            'date'           => $movement->created_at->toIso8601String(),
            'payment_method' => $movement->payment_method,
            'has_invoice'    => (bool) ($movement->has_invoice ?? false),
            'source'         => 'movement',
        ];
    }

    private function transformContribution(GoalContribution $contribution): array
    {
        return [
            'id'             => "gc_{$contribution->id}",
            'type'           => 'contribution',
            'type_badge'     => 'Abono',
            'amount'         => (float) ($contribution->amount ?? 0),
            'description'    => $contribution->description ?? '',
            'category'       => null,
            'goal_name'      => $contribution->goal?->name,
            'date'           => $contribution->created_at->toIso8601String(),
            'payment_method' => null,
            'has_invoice'    => false,
            'source'         => 'goal_contribution',
        ];
    }
}
