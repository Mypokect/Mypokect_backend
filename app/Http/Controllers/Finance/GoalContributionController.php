<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GoalContribution;
use App\Models\SavingGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GoalContributionController extends Controller
{
    use ApiResponse;

    /**
     * List contributions for a goal.
     *
     * Returns all contributions ordered by date (oldest first).
     */
    public function index(string $goalId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Verify goal exists and belongs to user
            $goal = SavingGoal::where('id', $goalId)
                ->where('user_id', $user->id)
                ->first();

            if (! $goal) {
                Log::warning("Goal {$goalId} not found for user {$user->id}");

                return $this->notFoundResponse('Goal not found');
            }

            Log::info("Fetching contributions for goal {$goalId}", ['user_id' => $user->id]);

            // Get contributions ordered by created_at ASC (oldest first)
            $contributions = GoalContribution::where('goal_id', $goalId)
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($contribution) use ($goal) {
                    return $this->transformToContribution($contribution, $goal);
                });

            return $this->successResponse([
                'data' => $contributions,
                'total' => count($contributions),
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching contributions: '.$e->getMessage());

            return $this->errorResponse('Error fetching contributions', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Create a contribution.
     *
     * Adds an amount to a saving goal.
     *
     * @bodyParam goal_id int required The saving goal ID. Example: 1
     * @bodyParam amount number required Contribution amount. Example: 50000
     * @bodyParam description string optional Note. Example: Ahorro quincenal
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Validate request
            $validator = Validator::make($request->all(), [
                'goal_id' => 'required|exists:saving_goals,id|numeric',
                'amount' => 'required|numeric|min:0.01',
                'description' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed for goal contribution', ['errors' => $validator->errors()]);

                return $this->validationErrorResponse($validator->errors());
            }

            // Verify goal exists and belongs to user
            $goal = SavingGoal::where('id', $request->goal_id)
                ->where('user_id', $user->id)
                ->first();

            if (! $goal) {
                Log::warning("Goal {$request->goal_id} not found for user {$user->id}");

                return $this->notFoundResponse('Goal not found');
            }

            // Create contribution
            DB::beginTransaction();

            $contribution = GoalContribution::create([
                'user_id' => $user->id,
                'goal_id' => $goal->id,
                'amount' => $request->amount,
                'description' => $request->description ?? 'Abono a meta',
            ]);

            DB::commit();

            Log::info("Contribution {$contribution->id} created for goal {$goal->id}", [
                'user_id' => $user->id,
                'amount' => $request->amount,
            ]);

            return $this->createdResponse(
                $this->transformToContribution($contribution, $goal),
                'Contribution created successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating contribution: '.$e->getMessage());

            return $this->errorResponse('Error creating contribution', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Delete a contribution.
     *
     * Removes a contribution from a goal and adjusts the saved amount.
     */
    public function destroy(string $contributionId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Find contribution and verify it belongs to user
            $contribution = GoalContribution::where('id', $contributionId)
                ->where('user_id', $user->id)
                ->first();

            if (! $contribution) {
                Log::warning("Contribution {$contributionId} not found for user {$user->id}");

                return $this->notFoundResponse('Contribution not found');
            }

            DB::beginTransaction();

            $goalId = $contribution->goal_id;
            $contribution->delete();

            DB::commit();

            Log::info("Contribution {$contributionId} deleted for user {$user->id}");

            return $this->deletedResponse('Contribution deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting contribution: '.$e->getMessage());

            return $this->errorResponse('Error deleting contribution', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Get contribution statistics.
     *
     * Returns aggregate stats: total, average, largest, smallest, percentage of goal, and last contribution date.
     */
    public function stats(string $goalId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Verify goal exists and belongs to user
            $goal = SavingGoal::where('id', $goalId)
                ->where('user_id', $user->id)
                ->first();

            if (! $goal) {
                Log::warning("Goal {$goalId} not found for user {$user->id}");

                return $this->notFoundResponse('Goal not found');
            }

            // Get all contributions for this goal
            $contributions = GoalContribution::where('goal_id', $goalId)
                ->where('user_id', $user->id)
                ->get();

            // Calculate statistics
            $totalContributions = $contributions->count();
            $totalAmount = $contributions->sum('amount');
            $averageContribution = $totalContributions > 0 ? $totalAmount / $totalContributions : 0;
            $largestContribution = $contributions->max('amount') ?? 0;
            $smallestContribution = $contributions->min('amount') ?? 0;
            $lastContributionDate = $contributions->max('created_at');

            $percentageOfGoal = $goal->target_amount > 0 ? ($totalAmount / $goal->target_amount) * 100 : 0;

            Log::info("Stats retrieved for goal {$goalId}", [
                'total_contributions' => $totalContributions,
                'total_amount' => $totalAmount,
            ]);

            return $this->successResponse([
                'total_contributions' => $totalContributions,
                'total_amount' => (float) $totalAmount,
                'average_contribution' => round($averageContribution, 2),
                'largest_contribution' => (float) $largestContribution,
                'smallest_contribution' => (float) $smallestContribution,
                'last_contribution_date' => $lastContributionDate?->toIso8601String(),
                'percentage_of_goal' => round($percentageOfGoal, 2),
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching stats: '.$e->getMessage());

            return $this->errorResponse('Error fetching statistics', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Transform GoalContribution to response format.
     */
    private function transformToContribution(GoalContribution $contribution, SavingGoal $goal): array
    {
        return [
            'id' => $contribution->id,
            'goal_id' => $goal->id,
            'goal_name' => $goal->name,
            'amount' => (float) $contribution->amount,
            'description' => $contribution->description,
            'date' => $contribution->created_at->toIso8601String(),
            'created_at' => $contribution->created_at->toIso8601String(),
            'updated_at' => $contribution->updated_at->toIso8601String(),
        ];
    }
}
