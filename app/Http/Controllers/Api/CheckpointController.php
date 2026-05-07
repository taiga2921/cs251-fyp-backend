<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckpointRequest;
use App\Http\Requests\UpdateCheckpointRequest;
use App\Http\Resources\CheckpointResource;
use App\Models\Checkpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CheckpointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'zone_id' => ['sometimes', 'uuid', 'exists:zones,id'],
                'is_active' => ['sometimes', 'boolean'],
                'location_type' => ['sometimes', 'in:outdoor,indoor'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
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

            $query = Checkpoint::query()->with('zone');

            if (array_key_exists('zone_id', $validated)) {
                $query->where('zone_id', $validated['zone_id']);
            }

            if (array_key_exists('is_active', $validated)) {
                $query->where('is_active', $validated['is_active']);
            }

            if (array_key_exists('location_type', $validated)) {
                $query->where('location_type', $validated['location_type']);
            }

            if (! empty($validated['search'])) {
                $query->where('name', 'like', '%'.$validated['search'].'%');
            }

            $checkpoints = $query
                ->latest()
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = CheckpointResource::collection($checkpoints)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Checkpoints retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StoreCheckpointRequest $request): JsonResponse
    {
        try {
            $checkpoint = Checkpoint::query()->create($request->validated());
            $checkpoint->load('zone');

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint created successfully.',
                'data' => (new CheckpointResource($checkpoint))->resolve(),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(Checkpoint $checkpoint): JsonResponse
    {
        try {
            $checkpoint->load('zone');

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint retrieved successfully.',
                'data' => (new CheckpointResource($checkpoint))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(UpdateCheckpointRequest $request, Checkpoint $checkpoint): JsonResponse
    {
        try {
            $checkpoint->update($request->validated());
            $checkpoint->load('zone');

            return response()->json([
                'success' => true,
                'message' => 'Checkpoint updated successfully.',
                'data' => (new CheckpointResource($checkpoint))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(Checkpoint $checkpoint): JsonResponse|Response
    {
        try {
            $checkpoint->forceDelete();

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
