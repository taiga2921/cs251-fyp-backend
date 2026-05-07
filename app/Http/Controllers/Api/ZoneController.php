<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Requests\StoreZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ZoneController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware(EnsureUserIsAdmin::class)->only(['store', 'update', 'destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sort' => ['sometimes', 'nullable', 'in:latest,oldest'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
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
            $perPage = $validated['per_page'] ?? 15;
            $sort = $validated['sort'] ?? 'latest';

            $query = Zone::query()->with('creator');

            if (! empty($validated['search'])) {
                $query->where('name', 'like', '%'.$validated['search'].'%');
            }

            $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc');

            $zones = $query->paginate($perPage)->withQueryString();
            $payload = ZoneResource::collection($zones)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Zones retrieved successfully.',
                'data' => $payload,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StoreZoneRequest $request): JsonResponse
    {
        try {
            $zone = Zone::query()->create($request->validated());
            $zone->load('creator');

            return response()->json([
                'success' => true,
                'message' => 'Zone created successfully.',
                'data' => (new ZoneResource($zone))->resolve(),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(Zone $zone): JsonResponse
    {
        try {
            $zone->load('creator');

            return response()->json([
                'success' => true,
                'message' => 'Zone retrieved successfully.',
                'data' => (new ZoneResource($zone))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(UpdateZoneRequest $request, Zone $zone): JsonResponse
    {
        try {
            $zone->update($request->validated());
            $zone->load('creator');

            return response()->json([
                'success' => true,
                'message' => 'Zone updated successfully.',
                'data' => (new ZoneResource($zone))->resolve(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(Zone $zone): JsonResponse|Response
    {
        try {
            $zone->delete();

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
