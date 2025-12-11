<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterTokenRequest;
use App\Models\NotificationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTokenController extends Controller
{
    /**
     * Register a new FCM token for push notifications.
     * 
     * @param RegisterTokenRequest $request
     * @return JsonResponse
     */
    public function register(RegisterTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['user_id'] = $request->user()->id;

        // Delete existing token if it exists (to update platform if changed)
        NotificationToken::where('token', $validated['token'])->delete();

        $token = NotificationToken::create($validated);

        return response()->json([
            'data' => $token,
            'message' => 'Token registrado exitosamente.',
        ], 201);
    }

    /**
     * Unregister an FCM token.
     * 
     * @param Request $request
     * @param string $token
     * @return JsonResponse
     */
    public function unregister(Request $request, string $token): JsonResponse
    {
        NotificationToken::where('user_id', $request->user()->id)
            ->where('token', $token)
            ->delete();

        return response()->json([
            'message' => 'Token eliminado exitosamente.',
        ]);
    }

    /**
     * Get all registered tokens for the authenticated user.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = NotificationToken::where('user_id', $request->user()->id)->get();

        return response()->json([
            'data' => $tokens,
        ]);
    }
}
