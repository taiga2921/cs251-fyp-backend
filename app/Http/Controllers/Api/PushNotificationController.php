<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PatrolPushNotificationService;
use App\Services\WebPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PushNotificationController extends Controller
{
    public function test(
        Request $request,
        WebPushNotificationService $webPush,
        PatrolPushNotificationService $patrolPush,
    ): JsonResponse {
        try {
            if (! $webPush->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Web Push is not configured. Set VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, and VAPID_SUBJECT in .env.',
                    'data' => null,
                ], 503);
            }

            $validator = Validator::make($request->all(), [
                'title' => ['sometimes', 'string', 'max:255'],
                'body' => ['sometimes', 'string', 'max:1000'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $validated = $validator->validated();
            $user = $request->user();

            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'data' => null,
                ], 401);
            }

            $delivery = $patrolPush->sendTestToUser(
                $user,
                $validated['title'] ?? 'Test notification',
                $validated['body'] ?? 'This is a test push notification.',
            );

            if ($delivery->skippedInvalidPayload > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Push payload could not be prepared for delivery.',
                    'data' => $delivery->toArray(),
                ], 422);
            }

            if (! $delivery->hasAttemptedDelivery()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No registered push subscriptions found for your account. Enable notifications in the PWA first.',
                    'data' => $delivery->toArray(),
                ], 422);
            }

            if (! $delivery->delivered()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test notification could not be delivered to any subscription.',
                    'data' => $delivery->toArray(),
                ], 502);
            }

            $message = $delivery->failed > 0 || $delivery->expired > 0
                ? sprintf(
                    'Test notification sent to %d subscription(s); %d failed, %d expired.',
                    $delivery->succeeded,
                    $delivery->failed,
                    $delivery->expired
                )
                : 'Test notification sent to your registered push subscriptions.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $delivery->toArray(),
            ], 200);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to send test notification.',
                'data' => null,
            ], 500);
        }
    }
}
