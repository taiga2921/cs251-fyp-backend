<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckpointEventMetricRequest;
use App\Http\Requests\UpdateCheckpointEventMetricRequest;
use App\Http\Resources\CheckpointEventMetricResource;
use App\Models\CheckpointEventMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CheckpointEventMetricController extends Controller
{
    /**
     * @return list<string>
     */
    protected function eagerRelations(): array
    {
        return ['checkpointEvent'];
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
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

            $metrics = CheckpointEventMetric::query()
                ->with($this->eagerRelations())
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = CheckpointEventMetricResource::collection($metrics)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event metrics retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StoreCheckpointEventMetricRequest $request): JsonResponse
    {
        try {
            $metric = CheckpointEventMetric::query()->create($request->validated());

            $metric->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event metric created successfully.',
                'data' => (new CheckpointEventMetricResource($metric))->resolve(),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(CheckpointEventMetric $checkpointEventMetric): JsonResponse
    {
        try {
            $checkpointEventMetric->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event metric retrieved successfully.',
                'data' => (new CheckpointEventMetricResource($checkpointEventMetric))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(UpdateCheckpointEventMetricRequest $request, CheckpointEventMetric $checkpointEventMetric): JsonResponse
    {
        try {
            $checkpointEventMetric->update($request->validated());

            $checkpointEventMetric->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint event metric updated successfully.',
                'data' => (new CheckpointEventMetricResource($checkpointEventMetric))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(CheckpointEventMetric $checkpointEventMetric): JsonResponse|Response
    {
        try {
            $checkpointEventMetric->delete();

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
