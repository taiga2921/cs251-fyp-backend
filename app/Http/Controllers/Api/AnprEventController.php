<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnprEventResource;
use App\Models\AnprEvent;
use App\Services\Anpr\AnprVehicleLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AnprEventController extends Controller
{
    public function __construct(
        protected AnprVehicleLinker $vehicleLinker
    ) {}

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
    protected function indexRelations(): array
    {
        return ['vehicle', 'camera'];
    }

    /**
     * @return list<string>
     */
    protected function detailRelations(): array
    {
        $relations = ['vehicle', 'camera'];

        if (Schema::hasTable('anpr_images')) {
            $relations[] = 'images';
        }

        return $relations;
    }

    /**
     * @deprecated Use indexRelations() or detailRelations() instead.
     *
     * @return list<string>
     */
    protected function eagerRelations(): array
    {
        return $this->detailRelations();
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
            $query = AnprEvent::query()
                ->with($this->indexRelations())
                ->withCount('images');

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
                'camera_id' => ['required', 'exists:cameras,id'],
                'blockchain_record_id' => ['nullable', 'exists:blockchain_records,id'],
                'plate_number' => ['required', 'string', 'max:20'],
                'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'detection_time' => ['required', 'date'],
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

            $validated = $validator->validated();
            $normalizedPlate = $this->vehicleLinker->normalizePlateNumber($validated['plate_number']);

            if ($normalizedPlate === '') {
                return $this->plateNumberValidationErrorResponse();
            }

            $vehicle = $this->vehicleLinker->linkOrCreate($validated['plate_number']);

            $anprEvent = AnprEvent::query()->create([
                'vehicle_id' => $vehicle->id,
                'camera_id' => $validated['camera_id'],
                'blockchain_record_id' => $validated['blockchain_record_id'] ?? null,
                'plate_number' => $normalizedPlate,
                'confidence' => $validated['confidence'],
                'detection_time' => $validated['detection_time'],
                'is_valid' => $validated['is_valid'] ?? true,
                'is_flagged' => $vehicle->status === 'flagged',
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);
            $anprEvent->load($this->detailRelations());

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
            $anprEvent->load($this->detailRelations());

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
                'vehicle_id' => ['prohibited'],
                'camera_id' => ['sometimes', 'required', 'exists:cameras,id'],
                'blockchain_record_id' => ['sometimes', 'nullable', 'exists:blockchain_records,id'],
                'plate_number' => ['sometimes', 'required', 'string', 'max:20'],
                'confidence' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
                'detection_time' => ['sometimes', 'required', 'date'],
                'is_flagged' => ['prohibited'],
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

            $validated = $validator->validated();

            if (array_key_exists('plate_number', $validated)) {
                $normalizedPlate = $this->vehicleLinker->normalizePlateNumber($validated['plate_number']);

                if ($normalizedPlate === '') {
                    return $this->plateNumberValidationErrorResponse();
                }

                $vehicle = $this->vehicleLinker->linkOrCreate($validated['plate_number']);
                $validated['plate_number'] = $normalizedPlate;
                $validated['vehicle_id'] = $vehicle->id;
                $validated['is_flagged'] = $vehicle->status === 'flagged';
            } else {
                $vehicle = $anprEvent->vehicle;

                if (! $vehicle && $anprEvent->plate_number) {
                    $normalizedPlate = $this->vehicleLinker->normalizePlateNumber($anprEvent->plate_number);

                    if ($normalizedPlate !== '') {
                        $vehicle = $this->vehicleLinker->linkOrCreate($anprEvent->plate_number);
                        $validated['vehicle_id'] = $vehicle->id;
                        $validated['plate_number'] = $normalizedPlate;
                    }
                }

                if ($vehicle) {
                    $validated['is_flagged'] = $vehicle->status === 'flagged';
                }
            }

            $anprEvent->update($validated);
            $anprEvent->load($this->detailRelations());

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

    protected function plateNumberValidationErrorResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data' => [
                'errors' => [
                    'plate_number' => ['Plate number cannot be empty after normalization.'],
                ],
            ],
        ], 422);
    }
}
