<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnprVehicleResource;
use App\Models\Vehicle;
use App\Services\Anpr\AnprVehicleLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class VehicleController extends Controller
{
    public function __construct(
        protected AnprVehicleLinker $vehicleLinker
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => ['sometimes', 'nullable', 'string', 'max:20'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]);

            if ($validator->fails()) {
                return $this->validationFailedResponse($validator);
            }

            $validated = $validator->validated();
            $query = Vehicle::query()->latest();

            if (! empty($validated['search'])) {
                $search = trim($validated['search']);
                $query->where('plate_number', 'like', '%'.$search.'%');
            }

            $vehicles = $query
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = AnprVehicleResource::collection($vehicles)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'Vehicles retrieved successfully.',
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
                'plate_number' => ['required', 'string', 'max:20'],
                'source' => ['prohibited'],
                'owner_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'status' => ['sometimes', 'string', Rule::in(['normal', 'flagged', 'whitelist'])],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->validationFailedResponse($validator);
            }

            $validated = $validator->validated();
            $normalizedPlate = $this->vehicleLinker->normalizePlateNumber($validated['plate_number']);

            if ($normalizedPlate === '') {
                return $this->plateNumberValidationErrorResponse();
            }

            if ($this->vehicleLinker->findByNormalizedPlate($validated['plate_number'])) {
                return $this->validationErrorResponse([
                    'plate_number' => ['A vehicle with this plate number already exists.'],
                ]);
            }

            $vehicle = Vehicle::query()->create([
                'plate_number' => $normalizedPlate,
                'owner_name' => $validated['owner_name'] ?? null,
                'vehicle_type' => $validated['vehicle_type'] ?? null,
                'status' => $validated['status'] ?? 'normal',
                'notes' => $validated['notes'] ?? null,
                'source' => 'manual',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vehicle created successfully.',
                'data' => AnprVehicleResource::make($vehicle),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Vehicle retrieved successfully.',
                'data' => AnprVehicleResource::make($vehicle),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'plate_number' => ['prohibited'],
                'source' => ['prohibited'],
                'owner_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'status' => ['sometimes', 'string', Rule::in(['normal', 'flagged', 'whitelist'])],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->validationFailedResponse($validator);
            }

            $vehicle->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Vehicle updated successfully.',
                'data' => AnprVehicleResource::make($vehicle->fresh()),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(Vehicle $vehicle): JsonResponse|Response
    {
        try {
            $vehicle->delete();

            return response()->noContent();
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    protected function validationErrorResponse(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data' => ['errors' => $errors],
        ], 422);
    }

    protected function plateNumberValidationErrorResponse(): JsonResponse
    {
        return $this->validationErrorResponse([
            'plate_number' => ['Plate number cannot be empty after normalization.'],
        ]);
    }

    protected function validationFailedResponse(\Illuminate\Contracts\Validation\Validator $validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data' => ['errors' => $validator->errors()->toArray()],
        ], 422);
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
