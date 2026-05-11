<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatrolRouteRequest;
use App\Http\Resources\PatrolRouteResource;
use App\Models\PatrolRoute;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Throwable;

class PatrolRouteController extends Controller
{
    public function store(StorePatrolRouteRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $recordedAt = $data['recorded_at'] ?? null;
            if ($recordedAt === null && isset($data['timestamp'])) {
                $recordedAt = Carbon::createFromTimestamp(((int) $data['timestamp']) / 1000);
            }

            $route = PatrolRoute::query()->create([
                'patrol_session_id' => $data['patrol_session_id'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'altitude' => $data['altitude'] ?? null,
                'recorded_at' => $recordedAt ?? now(),
            ]);

            $route->load(['patrolSession']);

            return response()->json([
                'success' => true,
                'message' => 'Patrol route point recorded successfully.',
                'data' => (new PatrolRouteResource($route))->resolve(),
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
