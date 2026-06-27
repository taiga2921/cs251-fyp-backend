<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthSessionResource;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\AuthAuditService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuthSessionController extends Controller
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $authUser = $request->user('api')?->loadMissing('role');
        $isAdmin = $this->isAdmin($authUser);

        $validated = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = RefreshToken::query()->with(['user.role'])->orderByDesc('created_at');

        if ($isAdmin) {
            if (! empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }
        } else {
            $query->where('user_id', $authUser?->getKey());
        }

        $currentSessionId = $this->resolveCurrentSessionId($request);
        $request->merge(['current_session_id' => $currentSessionId]);

        return AuthSessionResource::collection(
            $query->paginate($perPage)->withQueryString()
        );
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $authUser = $request->user('api')?->loadMissing('role');
        $token = RefreshToken::query()->with('user')->findOrFail($session);

        if (! $this->isAdmin($authUser) && $token->user_id !== $authUser?->getKey()) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators may perform this action.',
                'data' => null,
            ], 403);
        }

        $this->refreshTokenService->revoke($token);

        $this->authAuditService->record(
            AuthAuditService::EVENT_SESSION_REVOKED,
            AuthAuditService::STATUS_REVOKED,
            $request,
            user: $token->user,
            metadata: [
                'session_id' => $token->getKey(),
                'revoked_by_user_id' => $authUser?->getKey(),
            ],
        );

        $response = response()->json([
            'success' => true,
            'message' => 'Session revoked successfully.',
            'data' => null,
        ]);

        if ($this->isCurrentSession($request, $token)) {
            return $response->withCookie($this->refreshTokenService->forgetCookie());
        }

        return $response;
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $authUser = $request->user('api');

        if ($authUser === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        $revokedCount = $this->refreshTokenService->revokeAllForUser($authUser);

        $this->authAuditService->record(
            AuthAuditService::EVENT_LOGOUT_ALL_SUCCESS,
            AuthAuditService::STATUS_SUCCESS,
            $request,
            user: $authUser,
            metadata: ['revoked_count' => $revokedCount],
        );

        return response()->json([
            'success' => true,
            'message' => 'All sessions revoked successfully.',
            'data' => ['revoked_count' => $revokedCount],
        ])->withCookie($this->refreshTokenService->forgetCookie());
    }

    private function isAdmin(?User $user): bool
    {
        return is_string($user?->role?->name) && strcasecmp($user->role->name, 'Admin') === 0;
    }

    private function resolveCurrentSessionId(Request $request): ?string
    {
        $plainToken = $this->refreshTokenService->readPlainTokenFromRequest($request);

        if ($plainToken === null) {
            return null;
        }

        return $this->refreshTokenService->findByPlainToken($plainToken)?->getKey();
    }

    private function isCurrentSession(Request $request, RefreshToken $token): bool
    {
        $currentId = $this->resolveCurrentSessionId($request);

        return $currentId !== null && $currentId === $token->getKey();
    }
}
