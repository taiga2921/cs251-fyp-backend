<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationLogRequest;
use App\Http\Resources\LocationLogResource;
use App\Models\LocationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class LocationLogController extends Controller
{
    /**
     * @return list<string>
     */
    protected function eagerRelations(): array
    {
        return ['user', 'patrolSession'];
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'patrol_session_id' => ['sometimes', 'uuid', 'exists:patrol_sessions,id'],
                'user_id' => ['sometimes', 'uuid', 'exists:users,id'],
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

            $query = LocationLog::query()->with($this->eagerRelations());

            if (array_key_exists('patrol_session_id', $validated)) {
                $query->where('patrol_session_id', $validated['patrol_session_id']);
            }

            if (array_key_exists('user_id', $validated)) {
                $query->where('user_id', $validated['user_id']);
            }

            $query->orderBy('timestamp', 'asc');

            $locationLogs = $query
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = LocationLogResource::collection($locationLogs)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Location logs retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StoreLocationLogRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            if (! isset($data['id']) || $data['id'] === null || $data['id'] === '') {
                $data['id'] = (string) Str::uuid();
            }

            $data['server_received_at'] = now();

            $locationLog = LocationLog::query()->create($data);

            $locationLog->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'Location log created successfully.',
                'data' => (new LocationLogResource($locationLog))->resolve(),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(LocationLog $locationLog): JsonResponse
    {
        try {
            $locationLog->load($this->eagerRelations());

            return response()->json([
                'success' => true,
                'message' => 'Location log retrieved successfully.',
                'data' => (new LocationLogResource($locationLog))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(LocationLog $locationLog): JsonResponse|Response
    {
        try {
            $locationLog->delete();

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
