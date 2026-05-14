<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterTokenRequest;
use App\Http\Traits\ApiResponse;
use App\Models\NotificationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTokenController extends Controller
{
    use ApiResponse;

    /**
     * Register an FCM token.
     *
     * Stores a Firebase Cloud Messaging token for push notifications. Replaces existing token if duplicate.
     */
    public function register(RegisterTokenRequest $request): JsonResponse
    {
        $validated             = $request->validated();
        $validated['user_id']  = $request->user()->id;

        NotificationToken::where('token', $validated['token'])->delete();

        $token = NotificationToken::create($validated);

        return $this->createdResponse($token, 'Token registrado exitosamente.');
    }

    /**
     * Unregister an FCM token.
     *
     * Removes the specified token for the authenticated user.
     */
    public function unregister(Request $request, string $token): JsonResponse
    {
        NotificationToken::where('user_id', $request->user()->id)
            ->where('token', $token)
            ->delete();

        return $this->deletedResponse('Token eliminado exitosamente.');
    }

    /**
     * List FCM tokens.
     *
     * Returns all registered push notification tokens for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = NotificationToken::where('user_id', $request->user()->id)->get();

        return $this->successResponse($tokens);
    }
}
