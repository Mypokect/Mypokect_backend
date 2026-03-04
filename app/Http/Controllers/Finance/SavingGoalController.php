<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\SavingGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SavingGoalController extends Controller
{
    use ApiResponse;

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
            return $this->errorResponse('Error fetching saving goals: '.$e->getMessage());
        }
    }

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
            return $this->errorResponse('Error creating saving goal: '.$e->getMessage());
        }
    }

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
            return $this->errorResponse('Error fetching saving goal: '.$e->getMessage());
        }
    }

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
            return $this->errorResponse('Error updating saving goal: '.$e->getMessage());
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $user = Auth::user();
            $goal = SavingGoal::where('user_id', $user->id)->findOrFail($id);

            $goal->delete();

            return $this->deletedResponse('Saving goal deleted successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Error deleting saving goal: '.$e->getMessage());
        }
    }
}
