<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthAuditLogResource;
use App\Models\AuthAuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AuthAuditLogController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $validated = request()->validate([
            'user_id' => ['nullable', 'uuid'],
            'email' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:100'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                'success',
                'failure',
                'blocked',
                'revoked',
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $action = $validated['action'] ?? $validated['event_type'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = AuthAuditLog::query()->with(['user.role']);

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['email'])) {
            $query->where('email', 'like', '%'.strtolower(trim($validated['email'])).'%');
        }

        if ($action !== null && $action !== '') {
            $query->where('event_type', $action);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['date_from'])) {
            $query->where('occurred_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->where('occurred_at', '<=', $validated['date_to'].' 23:59:59');
        }

        return AuthAuditLogResource::collection(
            $query->orderByDesc('occurred_at')->paginate($perPage)->withQueryString()
        );
    }
}
