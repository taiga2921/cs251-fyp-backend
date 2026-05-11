<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncPwaLocationLogRequest;
use App\Http\Resources\LocationLogResource;
use App\Models\LocationLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

class PwaSyncController extends Controller
{
    public function sync(SyncPwaLocationLogRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $id = $data['locationLogId'];

            $existing = LocationLog::query()->find($id);
            if ($existing !== null) {
                $existing->load(['user', 'patrolSession']);

                return response()->json([
                    'success' => true,
                    'message' => 'Location log already synced.',
                    'data' => array_merge(
                        (new LocationLogResource($existing))->resolve(),
                        ['duplicate' => true],
                    ),
                ], 200);
            }

            $storedSource = match ($data['source']) {
                'manual' => 'sync',
                default => $data['source'],
            };

            $accuracy = $data['accuracy'] ?? null;
            if ($accuracy === null) {
                $accuracy = 0.0;
            }

            $attributes = [
                'id' => $id,
                'patrol_session_id' => $data['patrolId'],
                'user_id' => $data['userId'],
                'latitude' => $data['lat'],
                'longitude' => $data['lng'],
                'accuracy' => $accuracy,
                'timestamp' => $data['timestamp'],
                'server_received_at' => now(),
                'source' => $storedSource,
                'tracking_state' => $data['trackingState'],
                'speed' => $data['speed'] ?? null,
                'heading' => $data['heading'] ?? null,
            ];

            try {
                $locationLog = LocationLog::query()->create($attributes);
            } catch (QueryException $exception) {
                $retryExisting = LocationLog::query()->find($id);
                if ($retryExisting !== null) {
                    $retryExisting->load(['user', 'patrolSession']);

                    return response()->json([
                        'success' => true,
                        'message' => 'Location log already synced.',
                        'data' => array_merge(
                            (new LocationLogResource($retryExisting))->resolve(),
                            ['duplicate' => true],
                        ),
                    ], 200);
                }

                throw $exception;
            }

            $locationLog->load(['user', 'patrolSession']);

            return response()->json([
                'success' => true,
                'message' => 'Location log synced successfully.',
                'data' => array_merge(
                    (new LocationLogResource($locationLog))->resolve(),
                    ['duplicate' => false],
                ),
            ], 201);
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
