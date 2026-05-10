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
     * List all saving goals with location_breakdown per goal.
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();

            $goals = SavingGoal::where('user_id', $user->id)
                ->withSum('contributions', 'amount')
                ->with(['contributions:goal_id,amount,is_digital,location_name'])
                ->get()
                ->map(function ($goal) {
                    $savedAmount = $goal->contributions_sum_amount ?? 0;

                    $percentage = $goal->target_amount > 0
                        ? round(($savedAmount / $goal->target_amount) * 100, 1)
                        : 0;

                    $goal->saved_amount       = (float) $savedAmount;
                    $goal->percentage         = min($percentage, 100);
                    $goal->location_summary   = $this->computeLocationSummary($goal);

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
     */
    public function store(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'name'             => 'required|string|max:255',
            'target_amount'    => 'required|numeric|min:0',
            'deadline'         => 'nullable|date|after:today',
            'color'            => 'nullable|string|max:7',
            'emoji'            => 'nullable|string|max:10',
            'cuenta_asociada'  => 'nullable|string|max:100',
            'money_location'   => 'nullable|string|in:Efectivo,Banco,Nequi/Daviplata,Alcancía,Inversión',
            'is_digital'       => 'nullable|boolean',
            'location_name'    => 'nullable|string|max:100',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user     = Auth::user();
            $goalName = $request->input('name');

            $goal = SavingGoal::create([
                'user_id'         => $user->id,
                'tag_id'          => null,
                'name'            => $goalName,
                'target_amount'   => $request->input('target_amount'),
                'deadline'        => $request->input('deadline'),
                'color'           => $request->input('color', '#3B82F6'),
                'emoji'           => $request->input('emoji'),
                'cuenta_asociada' => $request->input('cuenta_asociada'),
                'money_location'  => $request->input('money_location', 'Efectivo'),
                'is_digital'      => $request->input('is_digital', false),
                'location_name'   => $request->input('location_name'),
            ]);

            $goal->saved_amount      = 0;
            $goal->percentage        = 0;
            $goal->location_summary  = [];

            return $this->createdResponse(['saving_goal' => $goal], 'Saving goal created successfully');

        } catch (\Exception $e) {
            Log::error('Error creating saving goal: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Get a single saving goal with location_breakdown.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $user = Auth::user();

            $goal = SavingGoal::where('user_id', $user->id)
                ->withSum('contributions', 'amount')
                ->with(['contributions:goal_id,amount,is_digital,location_name'])
                ->findOrFail($id);

            $savedAmount = $goal->contributions_sum_amount ?? 0;

            $percentage = $goal->target_amount > 0
                ? round(($savedAmount / $goal->target_amount) * 100, 1)
                : 0;

            $goal->saved_amount      = (float) $savedAmount;
            $goal->percentage        = min($percentage, 100);
            $goal->location_summary  = $this->computeLocationSummary($goal);

            return $this->successResponse($goal);

        } catch (\Exception $e) {
            Log::error('Error fetching saving goal: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Update a saving goal.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'name'            => 'nullable|string|max:255',
            'target_amount'   => 'nullable|numeric|min:0',
            'deadline'        => 'nullable|date|after:today',
            'color'           => 'nullable|string|max:7',
            'emoji'           => 'nullable|string|max:10',
            'cuenta_asociada' => 'nullable|string|max:100',
            'money_location'  => 'nullable|string|in:Efectivo,Banco,Nequi/Daviplata,Alcancía,Inversión',
            'is_digital'      => 'nullable|boolean',
            'location_name'   => 'nullable|string|max:100',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();
            $goal = SavingGoal::where('user_id', $user->id)->findOrFail($id);

            if ($request->has('name'))            $goal->name            = $request->input('name');
            if ($request->has('target_amount'))   $goal->target_amount   = $request->input('target_amount');
            if ($request->has('deadline'))         $goal->deadline        = $request->input('deadline');
            if ($request->has('color'))            $goal->color           = $request->input('color');
            if ($request->has('emoji'))            $goal->emoji           = $request->input('emoji');
            if ($request->has('cuenta_asociada'))  $goal->cuenta_asociada = $request->input('cuenta_asociada');
            if ($request->has('money_location'))   $goal->money_location  = $request->input('money_location');
            if ($request->has('is_digital'))       $goal->is_digital      = $request->input('is_digital');
            if ($request->has('location_name'))    $goal->location_name   = $request->input('location_name');
            if ($request->has('status'))           $goal->status          = $request->input('status');

            $goal->save();

            $goal->loadSum('contributions', 'amount');
            $goal->load(['contributions:goal_id,amount,is_digital,location_name']);

            $savedAmount = $goal->contributions_sum_amount ?? 0;

            $percentage = $goal->target_amount > 0
                ? round(($savedAmount / $goal->target_amount) * 100, 1)
                : 0;

            $goal->saved_amount      = (float) $savedAmount;
            $goal->percentage        = min($percentage, 100);
            $goal->location_summary  = $this->computeLocationSummary($goal);

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

    private function computeLocationSummary(SavingGoal $goal): array
    {
        $contributions = $goal->relationLoaded('contributions')
            ? $goal->contributions
            : $goal->contributions()->get(['goal_id', 'amount', 'is_digital', 'location_name']);

        return $contributions
            ->groupBy(function ($c) {
                if ($c->is_digital) {
                    $name = trim((string) ($c->location_name ?? ''));
                    return $name !== '' ? $name : 'Digital';
                }
                return 'Efectivo';
            })
            ->map(function ($group, $label) {
                return [
                    'location' => $label,
                    'total'    => round((float) $group->sum('amount'), 2),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->toArray();
    }
}
