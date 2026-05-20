<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;

trait CreatesPatrolUsers
{
    protected function userWithRole(string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }

    protected function adminUser(): User
    {
        return $this->userWithRole('Admin');
    }

    protected function guardUser(): User
    {
        return $this->userWithRole('Guard');
    }

    protected function securityOperatorUser(): User
    {
        return $this->userWithRole('Security Operator');
    }
}
