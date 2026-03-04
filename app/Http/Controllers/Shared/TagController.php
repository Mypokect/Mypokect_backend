<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\CreateTagRequest;
use App\Http\Requests\Tag\TagSuggestionRequest;
use App\Http\Resources\TagResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tag;
use App\Services\MovementAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TagController extends Controller
{
    use ApiResponse;

    protected MovementAIService $aiService;

    public function __construct(MovementAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get all tags for the authenticated user.
     * Optional filter: ?type=expense|income (only tags used in movements of that type)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $query = Tag::where('user_id', $user->id);

            if ($request->has('type') && in_array($request->type, ['expense', 'income'])) {
                $query->whereHas('movements', function ($q) use ($request) {
                    $q->where('type', $request->type);
                });
            }

            $tags = $query->orderBy('name')->get();

            return $this->successResponse(TagResource::collection($tags));

        } catch (\Exception $e) {
            Log::error('Error fetching tags: '.$e->getMessage());

            return $this->errorResponse('Error servidor: '.$e->getMessage());
        }
    }

    /**
     * Create a new tag.
     */
    public function store(CreateTagRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Normalize tag name: capitalize first letter
            $nameClean = ucfirst(strtolower(trim($request->name)));

            // firstOrCreate: returns existing or creates new (prevents duplicates)
            $tag = Tag::firstOrCreate([
                'user_id' => $user->id,
                'name' => $nameClean,
            ]);

            Log::info("Tag '{$tag->name}' created/retrieved for user {$user->id}");

            return $this->createdResponse(['tag' => new TagResource($tag)]);

        } catch (\Exception $e) {
            Log::error('Error saving tag: '.$e->getMessage());

            return $this->errorResponse('Error servidor: '.$e->getMessage());
        }
    }

    /**
     * Suggest tag based on description and amount using AI.
     * This endpoint does NOT save to database, only returns suggestion.
     */
    public function suggest(TagSuggestionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info("User {$user->id} requesting tag suggestion for: {$request->descripcion}");

            $suggestedTag = $this->aiService->suggestTag(
                $request->descripcion,
                $request->monto,
                $user
            );

            Log::info('Tag suggestion generated successfully', ['tag' => $suggestedTag]);

            return $this->successResponse(['tag' => $suggestedTag]);

        } catch (\Exception $e) {
            Log::error('Error generating tag suggestion: '.$e->getMessage());

            return $this->errorResponse('No se pudo sugerir: '.$e->getMessage());
        }
    }
}
