<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
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
    public function store(StoreUserRequest $request): UserResource
    {
        $validated = $request->validated();
        $user = new User;

        $user->fill($validated);
        $user->role_id = $validated['role_id'];
        $user->email_verified_at = $validated['email_verified_at'] ?? null;
        $user->save();

        return new UserResource($user->load('role'));
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

        $user->fill($validated);

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
    public function destroy(string $user): JsonResponse
    {
        $user = User::query()->findOrFail($user);
        $user->delete();

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
