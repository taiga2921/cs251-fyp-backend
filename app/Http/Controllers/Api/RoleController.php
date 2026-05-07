<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    /**
     * Display a paginated listing of roles.
     */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->paginate(15)
        );
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role): RoleResource
    {
        return new RoleResource($role);
    }
}
