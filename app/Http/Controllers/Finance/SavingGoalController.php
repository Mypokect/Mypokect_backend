<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\SavingGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SavingGoalController extends Controller
{
    use ApiResponse;

    /**
     * List all saving goals.
     *
     * Returns goals with calculated saved_amount and percentage from contributions.
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();

            $goals = SavingGoal::where('user_id', $user->id)
                ->withSum('contributions', 'amount')
                ->get()
                ->map(function ($goal) {
                    // Calculate saved amount from contributions only
                    // (no longer using movements/tags for goal tracking)
                    $savedAmount = $goal->contributions_sum_amount ?? 0;

                    $percentage = $goal->target_amount > 0
                        ? round(($savedAmount / $goal->target_amount) * 100, 1)
                        : 0;

                    $goal->saved_amount = (float) $savedAmount;
                    $goal->percentage = min($percentage, 100);

                    return $goal;
                });

            return $this->successResponse($goals);

        } catch (\Exception $e) {
            Log::error('Error fetching saving goals: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Create a saving goal.
     *
     * @bodyParam name string required Goal name. Example: Viaje a Europa
     * @bodyParam target_amount number required Target amount. Example: 5000000
     * @bodyParam deadline string optional Target date. Example: 2026-12-31
     * @bodyParam color string optional Hex color. Example: #3B82F6
     * @bodyParam emoji string optional Emoji icon. Example: ✈️
     */
    public function store(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'target_amount' => 'required|numeric|min:0',
            'deadline' => 'nullable|date|after:today',
            'color' => 'nullable|string|max:7',
            'emoji' => 'nullable|string|max:10',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();
            $goalName = $request->input('name');

            // Note: We no longer create tags for goals to avoid mixing them with regular tags
            // Goals are tracked independently via goal_contributions table
            $goal = SavingGoal::create([
                'user_id' => $user->id,
                'tag_id' => null, // No longer using tags for goals
                'name' => $goalName,
                'target_amount' => $request->input('target_amount'),
                'deadline' => $request->input('deadline'),
                'color' => $request->input('color', '#3B82F6'),
                'emoji' => $request->input('emoji'),
            ]);

            $goal->saved_amount = 0;
            $goal->percentage = 0;

            return $this->createdResponse(['saving_goal' => $goal], 'Saving goal created successfully');

        } catch (\Exception $e) {
            Log::error('Error creating saving goal: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Get a saving goal.
     *
     * Returns a single goal with saved_amount and percentage calculated from contributions.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $user = Auth::user();

            $goal = SavingGoal::where('user_id', $user->id)
                ->withSum('contributions', 'amount')
                ->findOrFail($id);

            // Calculate saved amount from contributions only
            // (no longer using movements/tags for goal tracking)
            $savedAmount = $goal->contributions_sum_amount ?? 0;

            $percentage = $goal->target_amount > 0
                ? round(($savedAmount / $goal->target_amount) * 100, 1)
                : 0;

            $goal->saved_amount = (float) $savedAmount;
            $goal->percentage = min($percentage, 100);

            return $this->successResponse($goal);

        } catch (\Exception $e) {
            Log::error('Error fetching saving goal: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Update a saving goal.
     *
     * @bodyParam name string optional New name. Example: Fondo de emergencia
     * @bodyParam target_amount number optional New target. Example: 3000000
     * @bodyParam deadline string optional New deadline. Example: 2026-06-30
     * @bodyParam color string optional New color. Example: #10B981
     * @bodyParam emoji string optional New emoji. Example: 🎯
     * @bodyParam status string optional Goal status. Example: completed
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'target_amount' => 'nullable|numeric|min:0',
            'deadline' => 'nullable|date|after:today',
            'color' => 'nullable|string|max:7',
            'emoji' => 'nullable|string|max:10',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();
            $goal = SavingGoal::where('user_id', $user->id)->findOrFail($id);

            if ($request->has('name')) {
                $goal->name = $request->input('name');
                // No longer updating tags since goals don't use them anymore
            }

            if ($request->has('target_amount')) {
                $goal->target_amount = $request->input('target_amount');
            }

            if ($request->has('deadline')) {
                $goal->deadline = $request->input('deadline');
            }

            if ($request->has('color')) {
                $goal->color = $request->input('color');
            }

            if ($request->has('emoji')) {
                $goal->emoji = $request->input('emoji');
            }

            if ($request->has('status')) {
                $goal->status = $request->input('status');
            }

            $goal->save();

            // Reload with contributions sum
            $goal->loadSum('contributions', 'amount');

            // Calculate saved amount from contributions only
            // (no longer using movements/tags for goal tracking)
            $savedAmount = $goal->contributions_sum_amount ?? 0;

            $percentage = $goal->target_amount > 0
                ? round(($savedAmount / $goal->target_amount) * 100, 1)
                : 0;

            $goal->saved_amount = (float) $savedAmount;
            $goal->percentage = min($percentage, 100);

            return $this->successResponse(['saving_goal' => $goal], 'Saving goal updated successfully');

        } catch (\Exception $e) {
            Log::error('Error updating saving goal: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Delete a saving goal.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $user = Auth::user();
            $goal = SavingGoal::where('user_id', $user->id)->findOrFail($id);

            $goal->delete();

            return $this->deletedResponse('Saving goal deleted successfully');

        } catch (\Exception $e) {
            Log::error('Error deleting saving goal: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }
}
