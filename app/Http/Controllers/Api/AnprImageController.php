<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnprImageResource;
use App\Models\AnprImage;
use App\Services\Anpr\AnprImageFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AnprImageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
                'anpr_event_id' => ['sometimes', 'uuid', 'exists:anpr_events,id'],
                'image_type' => ['sometimes', Rule::in(['full', 'plate', 'annotated'])],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $validated = $validator->validated();
            $query = AnprImage::query()->with('anprEvent')->latest();

            if (array_key_exists('anpr_event_id', $validated)) {
                $query->where('anpr_event_id', $validated['anpr_event_id']);
            }

            if (array_key_exists('image_type', $validated)) {
                $query->where('image_type', $validated['image_type']);
            }

            $anprImages = $query
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            $payload = AnprImageResource::collection($anprImages)->response()->getData(true);

            return response()->json([
                'success' => true,
                'message' => 'ANPR images retrieved successfully.',
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
                'anpr_event_id' => ['required', 'uuid', 'exists:anpr_events,id'],
                'image_type' => ['required', Rule::in(['full', 'plate', 'annotated'])],
                'file_path' => ['required', 'string', 'max:255'],
                'file_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'resolution' => ['sometimes', 'nullable', 'string', 'max:20'],
                'expires_at' => ['sometimes', 'nullable', 'date'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $anprImage = AnprImage::query()->create($validator->validated());
            $anprImage->load('anprEvent');

            return response()->json([
                'success' => true,
                'message' => 'ANPR image created successfully.',
                'data' => AnprImageResource::make($anprImage),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(AnprImage $anprImage): JsonResponse
    {
        try {
            $anprImage->load('anprEvent');

            return response()->json([
                'success' => true,
                'message' => 'ANPR image retrieved successfully.',
                'data' => AnprImageResource::make($anprImage),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function file(AnprImage $anprImage, AnprImageFileService $fileService): BinaryFileResponse|JsonResponse
    {
        try {
            $absolutePath = $fileService->resolveAbsolutePath((string) $anprImage->file_path);

            if ($absolutePath === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'ANPR image file is unavailable.',
                    'data' => null,
                ], 404);
            }

            $mimeType = mime_content_type($absolutePath) ?: 'application/octet-stream';

            return response()->file($absolutePath, [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, max-age=300',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, AnprImage $anprImage): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'anpr_event_id' => ['sometimes', 'required', 'uuid', 'exists:anpr_events,id'],
                'image_type' => ['sometimes', 'required', Rule::in(['full', 'plate', 'annotated'])],
                'file_path' => ['sometimes', 'required', 'string', 'max:255'],
                'file_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'resolution' => ['sometimes', 'nullable', 'string', 'max:20'],
                'expires_at' => ['sometimes', 'nullable', 'date'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $anprImage->update($validator->validated());
            $anprImage->load('anprEvent');

            return response()->json([
                'success' => true,
                'message' => 'ANPR image updated successfully.',
                'data' => AnprImageResource::make($anprImage),
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(AnprImage $anprImage): JsonResponse|Response
    {
        try {
            $anprImage->delete();

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
