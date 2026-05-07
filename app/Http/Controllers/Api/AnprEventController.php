<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnprEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AnprEventController extends Controller
{
    /**
     * @return list<string>
     */
    protected function eagerRelations(): array
    {
        $relations = ['vehicle', 'camera'];

        // Load optional relations only when their tables are present in this deployment.
        if (Schema::hasTable('anpr_images')) {
            $relations[] = 'images';
        }

        if (Schema::hasTable('anpr_event_logs')) {
            $relations[] = 'logs';
        }

        return $relations;
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
            $anprEvents = AnprEvent::query()
                ->with($this->eagerRelations())
                ->latest('detection_time')
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            return response()->json([
                'success' => true,
                'message' => 'ANPR events retrieved successfully.',
                'data' => $anprEvents,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_id' => ['nullable', 'exists:vehicles,id'],
                'camera_id' => ['required', 'exists:cameras,id'],
                'blockchain_record_id' => ['nullable', 'exists:blockchain_records,id'],
                'plate_number' => ['required', 'string', 'max:20'],
                'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'detection_time' => ['required', 'date'],
                'is_flagged' => ['sometimes', 'boolean'],
                'is_valid' => ['sometimes', 'boolean'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $anprEvent = AnprEvent::query()->create($validator->validated());
            $anprEvent->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'ANPR event created successfully.',
                'data' => $anprEvent,
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(AnprEvent $anprEvent): JsonResponse
    {
        try {
            $anprEvent->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'ANPR event retrieved successfully.',
                'data' => $anprEvent,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, AnprEvent $anprEvent): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_id' => ['sometimes', 'nullable', 'exists:vehicles,id'],
                'camera_id' => ['sometimes', 'required', 'exists:cameras,id'],
                'blockchain_record_id' => ['sometimes', 'nullable', 'exists:blockchain_records,id'],
                'plate_number' => ['sometimes', 'required', 'string', 'max:20'],
                'confidence' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
                'detection_time' => ['sometimes', 'required', 'date'],
                'is_flagged' => ['sometimes', 'boolean'],
                'is_valid' => ['sometimes', 'boolean'],
                'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $anprEvent->update($validator->validated());
            $anprEvent->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'ANPR event updated successfully.',
                'data' => $anprEvent,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(AnprEvent $anprEvent): JsonResponse|Response
    {
        try {
            $anprEvent->delete();

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
