<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckpointEventRequest;
use App\Http\Requests\UpdateCheckpointEventRequest;
use App\Http\Resources\CheckpointEventResource;
use App\Models\CheckpointEvent;
use App\Services\PatrolBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CheckpointEventController extends Controller
{
    /**
     * @return list<string>
     */
    protected function eagerRelations(): array
    {
        return ['patrolSession', 'checkpoint', 'metric'];
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'patrol_session_id' => ['sometimes', 'uuid', 'exists:patrol_sessions,id'],
                'checkpoint_id' => ['sometimes', 'uuid', 'exists:checkpoints,id'],
                'status' => ['sometimes', 'in:pending,verified,suspicious,uncertain,rejected'],
                'detection_type' => ['sometimes', 'nullable', 'in:continuous,resume,manual'],
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

            $query = CheckpointEvent::query()->with($this->eagerRelations());

            if (array_key_exists('patrol_session_id', $validated)) {
                $query->where('patrol_session_id', $validated['patrol_session_id']);
            }

            if (array_key_exists('checkpoint_id', $validated)) {
                $query->where('checkpoint_id', $validated['checkpoint_id']);
            }

            if (array_key_exists('status', $validated)) {
                $query->where('status', $validated['status']);
            }

            if (array_key_exists('detection_type', $validated)) {
                $query->where('detection_type', $validated['detection_type']);
            }

            $sort = $validated['sort'] ?? 'latest';
            $query->orderBy('detected_at', $sort === 'oldest' ? 'asc' : 'desc');

            $checkpointEvents = $query
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = CheckpointEventResource::collection($checkpointEvents)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint events retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StoreCheckpointEventRequest $request, PatrolBroadcastService $broadcastService): JsonResponse
    {
        try {
            $checkpointEvent = CheckpointEvent::query()->create($request->validated());

            $checkpointEvent->load($this->eagerRelations());
            $broadcastService->checkpointUpdated($checkpointEvent);

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event created successfully.',
                'data' => (new CheckpointEventResource($checkpointEvent))->resolve(),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(CheckpointEvent $checkpointEvent): JsonResponse
    {
        try {
            $checkpointEvent->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event retrieved successfully.',
                'data' => (new CheckpointEventResource($checkpointEvent))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(
        UpdateCheckpointEventRequest $request,
        CheckpointEvent $checkpointEvent,
        PatrolBroadcastService $broadcastService,
    ): JsonResponse {
        try {
            $checkpointEvent->update($request->validated());

            $checkpointEvent->load($this->eagerRelations());
            $broadcastService->checkpointUpdated($checkpointEvent);

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event updated successfully.',
                'data' => (new CheckpointEventResource($checkpointEvent))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(CheckpointEvent $checkpointEvent): JsonResponse|Response
    {
        try {
            $checkpointEvent->delete();

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
