<?php

namespace Tests\Feature;

use App\Models\PatrolSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_access_admin_only_user_endpoints(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'api')
            ->getJson('/api/users')
            ->assertOk();
    }

    public function test_guard_cannot_access_admin_only_user_endpoints(): void
    {
        $guard = $this->guardUser();

        $this->actingAs($guard, 'api')
            ->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only administrators may perform this action.');
    }

    public function test_security_operator_can_access_patrol_monitoring_endpoints(): void
    {
        $operator = $this->securityOperatorUser();
        $patrol = PatrolSession::factory()->create();

        $this->actingAs($operator, 'api')
            ->getJson('/api/patrol-sessions/'.$patrol->id)
            ->assertOk();

        $this->actingAs($operator, 'api')
            ->getJson('/api/patrol-sessions/'.$patrol->id.'/summary')
            ->assertOk();

        $this->actingAs($operator, 'api')
            ->getJson('/api/patrol-routes?patrol_session_id='.$patrol->id)
            ->assertOk();
    }

    public function test_unauthenticated_users_cannot_access_protected_patrol_endpoints(): void
    {
        $patrol = PatrolSession::factory()->create();

        $this->getJson('/api/patrol-sessions')->assertUnauthorized();
        $this->getJson('/api/patrol-sessions/'.$patrol->id.'/summary')->assertUnauthorized();
        $this->postJson('/api/patrol-sessions/'.$patrol->id.'/validate')->assertUnauthorized();
        $this->postJson('/api/pwa/sync', [])->assertUnauthorized();
    }
}
