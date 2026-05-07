<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCameraRequest;
use App\Http\Requests\UpdateCameraRequest;
use App\Models\Camera;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class CameraController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Cameras retrieved successfully.',
                'data' => Camera::query()->latest()->get(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(StoreCameraRequest $request): JsonResponse
    {
        try {
            $camera = Camera::query()->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Camera created successfully.',
                'data' => $camera,
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(Camera $camera): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Camera retrieved successfully.',
                'data' => $camera,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(UpdateCameraRequest $request, Camera $camera): JsonResponse
    {
        try {
            $camera->update($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Camera updated successfully.',
                'data' => $camera->fresh(),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(Camera $camera): JsonResponse|Response
    {
        try {
            $camera->delete();

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
