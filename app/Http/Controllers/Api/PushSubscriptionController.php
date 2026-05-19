<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $subscription = PushSubscription::query()->updateOrCreate(
                ['endpoint' => $validated['endpoint']],
                [
                    'user_id' => auth('api')->id(),
                    'keys' => $validated['keys'],
                    'user_agent' => $validated['user_agent'] ?? $request->userAgent(),
                    'last_used_at' => now(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Push subscription saved successfully.',
                'data' => [
                    'id' => $subscription->id,
                    'endpoint' => $subscription->endpoint,
                    'user_id' => $subscription->user_id,
                    'last_used_at' => $subscription->last_used_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(PushSubscription $push_subscription): JsonResponse|Response
    {
        try {
            $userId = auth('api')->id();

            if ($push_subscription->user_id !== null && $push_subscription->user_id !== $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden.',
                    'data' => null,
                ], 403);
            }

            $push_subscription->delete();

            return response()->noContent();
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    protected function errorResponse(Throwable $e): JsonResponse
    {
        report($e);

        $message = config('app.debug')
            ? $e->getMessage()
            : 'An unexpected error occurred.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 500);
    }
}
