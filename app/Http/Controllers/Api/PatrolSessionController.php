<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPatrolMonitoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatrolSessionRequest;
use App\Http\Requests\UpdatePatrolSessionRequest;
use App\Http\Resources\PatrolSessionResource;
use App\Models\PatrolSession;
use App\Services\PatrolBroadcastService;
use App\Services\PatrolSessionSummaryService;
use App\Services\PatrolValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PatrolSessionController extends Controller
{
    use AuthorizesPatrolMonitoring;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePatrolMonitoring();

        try {
            $validator = Validator::make($request->all(), [
                'user_id' => ['sometimes', 'exists:users,id'],
                'zone_id' => ['sometimes', 'uuid', 'exists:zones,id'],
                'status' => ['sometimes', 'in:active,completed,aborted'],
                'sort' => ['sometimes', 'nullable', 'in:latest,oldest'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $validated = $validator->validated();
            $query = PatrolSession::query()->with(['user', 'zone', 'blockchainRecord']);

            if (array_key_exists('user_id', $validated)) {
                $query->where('user_id', $validated['user_id']);
            }

            if (array_key_exists('zone_id', $validated)) {
                $query->where('zone_id', $validated['zone_id']);
            }

            if (array_key_exists('status', $validated)) {
                $query->where('status', $validated['status']);
            }

            $sort = $validated['sort'] ?? 'latest';
            $query->orderBy('started_at', $sort === 'oldest' ? 'asc' : 'desc');

            $patrolSessions = $query
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = PatrolSessionResource::collection($patrolSessions)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Patrol sessions retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StorePatrolSessionRequest $request, PatrolBroadcastService $broadcastService): JsonResponse
    {
        try {
            $patrolSession = PatrolSession::query()->create($request->validated());
            $patrolSession->refresh();
            $patrolSession->load(['user', 'zone', 'blockchainRecord']);

            if ($patrolSession->status === 'active') {
                $broadcastService->sessionStarted($patrolSession);
            }

            return response()->json([
                'success' => true,
                'message' => 'Patrol session created successfully.',
                'data' => (new PatrolSessionResource($patrolSession))->resolve(),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(PatrolSession $patrolSession): JsonResponse
    {
        $this->authorizePatrolMonitoring();

        try {
            $patrolSession->load(['user', 'zone', 'blockchainRecord']);

            return response()->json([
                'success' => true,
                'message' => 'Patrol session retrieved successfully.',
                'data' => (new PatrolSessionResource($patrolSession))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function summary(PatrolSession $patrolSession, PatrolSessionSummaryService $summaryService): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Patrol session summary retrieved successfully.',
                'data' => $summaryService->build($patrolSession),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function validateSession(
        PatrolSession $patrolSession,
        PatrolValidationService $validationService,
        PatrolBroadcastService $broadcastService,
    ): JsonResponse {
        try {
            $result = $validationService->validatePatrolSession($patrolSession);
            $broadcastService->validationCompleted($patrolSession, $result);

            return response()->json([
                'success' => true,
                'message' => 'Patrol session validation completed.',
                'data' => $result,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(
        UpdatePatrolSessionRequest $request,
        PatrolSession $patrolSession,
        PatrolBroadcastService $broadcastService,
    ): JsonResponse {
        try {
            $previousStatus = $patrolSession->status;
            $patrolSession->update($request->validated());
            $patrolSession->load(['user', 'zone', 'blockchainRecord']);

            if (
                $patrolSession->status !== $previousStatus
                && in_array($patrolSession->status, ['completed', 'aborted'], true)
            ) {
                $broadcastService->sessionCompleted($patrolSession);
            }

            return response()->json([
                'success' => true,
                'message' => 'Patrol session updated successfully.',
                'data' => (new PatrolSessionResource($patrolSession))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(PatrolSession $patrolSession): JsonResponse|Response
    {
        try {
            $patrolSession->forceDelete();

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
