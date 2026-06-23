<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnprEventResource;
use App\Models\AnprEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AnprEventController extends Controller
{
    /**
     * @var list<string>
     */
    protected const ALLOWED_SORT_FIELDS = [
        'detection_time',
        'created_at',
        'plate_number',
        'confidence',
    ];

    /**
     * @return list<string>
     */
    protected function eagerRelations(): array
    {
        $relations = ['vehicle', 'camera'];

        if (Schema::hasTable('anpr_images')) {
            $relations[] = 'images';
        }

        return $relations;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'plate_number' => ['sometimes', 'nullable', 'string', 'max:20'],
                'search' => ['sometimes', 'nullable', 'string', 'max:20'],
                'is_valid' => ['sometimes', 'nullable', 'boolean'],
                'is_flagged' => ['sometimes', 'nullable', 'boolean'],
                'date_from' => ['sometimes', 'nullable', 'date'],
                'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
                'camera_id' => ['sometimes', 'nullable', 'uuid', 'exists:cameras,id'],
                'sort' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ALLOWED_SORT_FIELDS)],
                'direction' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
                'since' => ['sometimes', 'nullable', 'date'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $validated = $validator->validated();
            $query = AnprEvent::query()->with($this->eagerRelations());

            $plateSearch = $validated['plate_number'] ?? $validated['search'] ?? null;
            if (is_string($plateSearch) && trim($plateSearch) !== '') {
                $query->where('plate_number', 'like', '%'.trim($plateSearch).'%');
            }

            if (array_key_exists('is_valid', $validated)) {
                $query->where('is_valid', (bool) $validated['is_valid']);
            }

            if (array_key_exists('is_flagged', $validated)) {
                $query->where('is_flagged', (bool) $validated['is_flagged']);
            }

            if (array_key_exists('camera_id', $validated)) {
                $query->where('camera_id', $validated['camera_id']);
            }

            if (array_key_exists('date_from', $validated)) {
                $query->whereDate('detection_time', '>=', $validated['date_from']);
            }

            if (array_key_exists('date_to', $validated)) {
                $query->whereDate('detection_time', '<=', $validated['date_to']);
            }

            if (array_key_exists('since', $validated)) {
                $since = Carbon::parse($validated['since']);
                // Prefer created_at for queue-delivered events; also include detection_time
                // so rows with older detection_time but recent persistence are not missed.
                $query->where(function ($q) use ($since) {
                    $q->where('created_at', '>', $since)
                        ->orWhere('detection_time', '>', $since);
                });
            }

            $sortField = $validated['sort'] ?? 'detection_time';
            $sortDirection = $validated['direction'] ?? 'desc';

            $anprEvents = $query
                ->orderBy($sortField, $sortDirection)
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = AnprEventResource::collection($anprEvents)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'ANPR events retrieved successfully.',
                'data' => $payload,
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
                'data' => AnprEventResource::make($anprEvent),
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
                'data' => AnprEventResource::make($anprEvent),
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
                'data' => AnprEventResource::make($anprEvent),
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
