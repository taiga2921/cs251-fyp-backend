<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthAuditService;
use App\Services\Auth\PasswordSetupService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly PasswordSetupService $passwordSetupService,
        private readonly RefreshTokenService $refreshTokenService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    /**
     * Display a paginated listing of users.
     */
    public function index(): AnonymousResourceCollection
    {
        $query = User::query()->with('role');

        if (request()->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif (request()->boolean('include_trashed')) {
            $query->withTrashed();
        }

        return UserResource::collection($query->get());
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = new User;

        $user->fill(collect($validated)->only([
            'name',
            'email',
            'password',
            'phone',
            'address',
            'profile_picture_url',
            'profile_version',
        ])->all());
        $user->role_id = $validated['role_id'];
        $user->email_verified_at = $validated['email_verified_at'] ?? null;
        $user->setup_required = true;
        $user->save();

        $user->load('role');
        $setupSession = $this->passwordSetupService->createForUser($user);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => (new UserResource($user))->resolve(),
            'password_setup' => [
                'token' => $setupSession['plain_token'],
                'expires_at' => $setupSession['model']->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(string $user): UserResource
    {
        $user = User::query()->withTrashed()->with('role')->findOrFail($user);

        return new UserResource($user);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(UpdateUserRequest $request, string $user): UserResource
    {
        $user = User::query()->withTrashed()->findOrFail($user);
        $validated = $request->validated();

        $safeFields = collect($validated)->only([
            'name',
            'email',
            'phone',
            'address',
            'profile_picture_url',
            'profile_version',
        ])->all();

        if (array_key_exists('password', $validated) && filled($validated['password'])) {
            $safeFields['password'] = $validated['password'];
        }

        $user->fill($safeFields);

        if (array_key_exists('password', $validated) && filled($validated['password'])) {
            $user->last_password_changed_at = now();
        }

        if (array_key_exists('role_id', $validated)) {
            $user->role_id = $validated['role_id'];
        }

        if (array_key_exists('email_verified_at', $validated)) {
            $user->email_verified_at = $validated['email_verified_at'];
        }

        $user->save();

        return new UserResource($user->load('role'));
    }

    /**
     * Soft delete the specified user.
     */
    public function destroy(Request $request, string $user): JsonResponse
    {
        $user = User::query()->findOrFail($user);
        $admin = $request->user('api');
        $revokedCount = $this->refreshTokenService->revokeAllForUser($user);
        $user->delete();

        $this->authAuditService->record(
            AuthAuditService::EVENT_USER_DISABLED_SESSIONS_REVOKED,
            AuthAuditService::STATUS_REVOKED,
            $request,
            user: $user,
            metadata: [
                'disabled_user_id' => $user->getKey(),
                'disabled_user_email' => $user->email,
                'disabled_by_user_id' => $admin?->getKey(),
                'revoked_count' => $revokedCount,
            ],
        );

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(string $user): UserResource
    {
        $user = User::query()->withTrashed()->findOrFail($user);
        $user->restore();

        return new UserResource($user->load('role'));
    }
}
