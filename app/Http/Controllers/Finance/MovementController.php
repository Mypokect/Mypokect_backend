<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Movement\CreateMovementRequest;
use App\Http\Requests\Movement\VoiceSuggestionRequest;
use App\Http\Resources\MovementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Movement;
use App\Models\Tag;
use App\Services\MovementAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MovementController extends Controller
{
    use ApiResponse;

    protected MovementAIService $aiService;

    public function __construct(MovementAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * List all movements.
     *
     * Returns all movements for the authenticated user, ordered by most recent, with their associated tag.
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            Log::info("User {$user->id} is requesting movements list.");

            $movements = $user->movements()
                ->with('tag')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse(MovementResource::collection($movements));

        } catch (\Exception $e) {
            Log::error('Error fetching movements: '.$e->getMessage());

            return $this->errorResponse('An error occurred while fetching movements: '.$e->getMessage());
        }
    }

    /**
     * Create a movement.
     *
     * Creates an income or expense movement. Automatically finds or creates the tag by name.
     */
    public function store(CreateMovementRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();

            DB::beginTransaction();

            // Handle tag: find or create by name
            $tagId = null;
            if ($request->has('tag_name') && ! empty($request->tag_name)) {
                $tagName = ucfirst(strtolower(trim($request->tag_name)));

                $tag = Tag::firstOrCreate(
                    ['user_id' => $user->id, 'name' => $tagName]
                );

                $tagId = $tag->id;
            }

            // Create movement
            $movement = Movement::create([
                'type' => $request->type,
                'amount' => $request->amount,
                'description' => $request->description ?? 'Movimiento',
                'payment_method' => $request->payment_method,
                'has_invoice' => $request->has_invoice ?? false,
                'user_id' => $user->id,
                'tag_id' => $tagId,
            ]);

            // Load tag relationship for response
            $movement->load('tag');

            DB::commit();

            Log::info("Movement {$movement->id} created successfully by user {$user->id}");

            return $this->createdResponse(new MovementResource($movement), 'Movimiento creado');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating movement: '.$e->getMessage());

            return $this->errorResponse('An error occurred while creating the movement: '.$e->getMessage());
        }
    }

    /**
     * Suggest movement from voice.
     *
     * Uses AI to parse a voice transcription and suggest movement fields (amount, tag, type, description).
     */
    public function suggestFromVoice(VoiceSuggestionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            Log::info("User {$user->id} is requesting voice suggestion for: {$request->transcripcion}");

            $suggestion = $this->aiService->suggestFromVoice(
                $request->transcripcion,
                $user
            );

            Log::info('Voice suggestion generated successfully', ['suggestion' => $suggestion]);

            return $this->successResponse(['movement_suggestion' => $suggestion]);

        } catch (\Exception $e) {
            Log::error('Error processing voice suggestion: '.$e->getMessage());

            return $this->errorResponse('Error AI process: '.$e->getMessage());
        }
    }
}
