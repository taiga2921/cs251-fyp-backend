<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPatrolMonitoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatrolRouteRequest;
use App\Http\Resources\PatrolRouteResource;
use App\Models\PatrolRoute;
use App\Services\PatrolBroadcastService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PatrolRouteController extends Controller
{
    use AuthorizesPatrolMonitoring;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePatrolMonitoring();

        try {
            $validator = Validator::make($request->all(), [
                'patrol_session_id' => ['sometimes', 'uuid', 'exists:patrol_sessions,id'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
                'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $validated = $validator->validated();

            $query = PatrolRoute::query()->with(['patrolSession']);

            if (array_key_exists('patrol_session_id', $validated)) {
                $query->where('patrol_session_id', $validated['patrol_session_id']);
            }

            $query->orderBy('recorded_at', 'asc');

            $patrolRoutes = $query
                ->paginate($validated['per_page'] ?? 500)
                ->withQueryString();

            $payload = PatrolRouteResource::collection($patrolRoutes)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Patrol routes retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StorePatrolRouteRequest $request, PatrolBroadcastService $broadcastService): JsonResponse
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
            $broadcastService->routeUpdated($route);

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
