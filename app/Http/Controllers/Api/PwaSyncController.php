<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncPwaLocationLogRequest;
use App\Http\Resources\LocationLogResource;
use App\Models\LocationLog;
use App\Services\LocationLogTimestampService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

class PwaSyncController extends Controller
{
    public function sync(SyncPwaLocationLogRequest $request, LocationLogTimestampService $timestampService): JsonResponse
    {
        try {
            $data = $request->validated();
            $id = $data['locationLogId'];

            $existing = LocationLog::query()->find($id);
            if ($existing !== null) {
                if ($this->payloadMatchesExisting($data, $existing)) {
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

                return response()->json([
                    'success' => false,
                    'message' => 'Location log ID already exists with a different payload.',
                    'data' => null,
                ], 409);
            }

            $data['timestamp'] = $timestampService->normalizeForPatrolSession(
                $data['patrolId'],
                (int) $data['timestamp'],
            );

            $storedSource = $this->storedSource($data['source']);

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
                    if ($this->payloadMatchesExisting($data, $retryExisting)) {
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

                    return response()->json([
                        'success' => false,
                        'message' => 'Location log ID already exists with a different payload.',
                        'data' => null,
                    ], 409);
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function payloadMatchesExisting(array $data, LocationLog $existing): bool
    {
        $accuracy = $data['accuracy'] ?? 0.0;

        return $existing->patrol_session_id === $data['patrolId']
            && $existing->user_id === $data['userId']
            && (float) $existing->latitude === (float) $data['lat']
            && (float) $existing->longitude === (float) $data['lng']
            && (int) $existing->timestamp === (int) $data['timestamp']
            && $existing->source === $this->storedSource($data['source'])
            && $existing->tracking_state === $data['trackingState']
            && (float) ($existing->accuracy ?? 0) === (float) $accuracy
            && $this->nullableFloatEquals($existing->speed, $data['speed'] ?? null)
            && $this->nullableFloatEquals($existing->heading, $data['heading'] ?? null);
    }

    protected function storedSource(string $source): string
    {
        return match ($source) {
            'manual' => 'sync',
            default => $source,
        };
    }

    protected function nullableFloatEquals(mixed $stored, mixed $incoming): bool
    {
        if ($stored === null && $incoming === null) {
            return true;
        }
        if ($stored === null || $incoming === null) {
            return false;
        }

        return (float) $stored === (float) $incoming;
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
