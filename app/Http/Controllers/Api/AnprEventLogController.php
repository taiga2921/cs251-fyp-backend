<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnprEventLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AnprEventLogController extends Controller
{
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
            $anprEventLogs = AnprEventLog::query()
                ->with('anprEvent')
                ->latest()
                ->paginate($validated['per_page'] ?? 15)
                ->withQueryString();

            return response()->json([
                'success' => true,
                'message' => 'ANPR event logs retrieved successfully.',
                'data' => $anprEventLogs,
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
                'stage' => ['required', 'string', 'max:50'],
                'message' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $anprEventLog = AnprEventLog::query()->create($validator->validated());
            $anprEventLog->load('anprEvent');

            return response()->json([
                'success' => true,
                'message' => 'ANPR event log created successfully.',
                'data' => $anprEventLog,
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(AnprEventLog $anprEventLog): JsonResponse
    {
        try {
            $anprEventLog->load('anprEvent');

            return response()->json([
                'success' => true,
                'message' => 'ANPR event log retrieved successfully.',
                'data' => $anprEventLog,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, AnprEventLog $anprEventLog): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'anpr_event_id' => ['sometimes', 'required', 'uuid', 'exists:anpr_events,id'],
                'stage' => ['sometimes', 'required', 'string', 'max:50'],
                'message' => ['sometimes', 'nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $anprEventLog->update($validator->validated());
            $anprEventLog->load('anprEvent');

            return response()->json([
                'success' => true,
                'message' => 'ANPR event log updated successfully.',
                'data' => $anprEventLog,
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroy(AnprEventLog $anprEventLog): JsonResponse|Response
    {
        try {
            $anprEventLog->delete();

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
