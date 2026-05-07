<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class VehicleController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Vehicles retrieved successfully.',
                'data' => Vehicle::query()->latest()->get(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
                'owner_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'status' => ['sometimes', 'string', Rule::in(['normal', 'flagged', 'whitelist'])],
                'source' => ['sometimes', 'string', Rule::in(['manual', 'auto_detected'])],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $vehicle = Vehicle::query()->create($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Vehicle created successfully.',
                'data' => $vehicle,
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
                'data' => $vehicle,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'plate_number' => [
                    'sometimes',
                    'string',
                    'max:20',
                    Rule::unique('vehicles', 'plate_number')->ignore($vehicle->id),
                ],
                'owner_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'status' => ['sometimes', 'string', Rule::in(['normal', 'flagged', 'whitelist'])],
                'source' => ['sometimes', 'string', Rule::in(['manual', 'auto_detected'])],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $vehicle->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Vehicle updated successfully.',
                'data' => $vehicle->fresh(),
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
