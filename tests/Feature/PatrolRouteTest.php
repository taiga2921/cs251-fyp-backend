<?php

namespace Tests\Feature;

use App\Models\PatrolRoute;
use App\Models\PatrolSession;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatrolRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_authenticated_user_can_create_route_breadcrumb(): void
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/patrol-routes', [
                'patrol_session_id' => $patrol->id,
                'latitude' => 3.139,
                'longitude' => 101.6869,
                'recorded_at' => '2026-05-20T10:00:00+08:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.patrol_session_id', $patrol->id);

        $this->assertSame(1, PatrolRoute::query()->where('patrol_session_id', $patrol->id)->count());
    }

    public function test_patrol_routes_filter_by_patrol_session_id(): void
    {
        $user = User::factory()->create();
        $patrolA = PatrolSession::factory()->create(['user_id' => $user->id]);
        $patrolB = PatrolSession::factory()->create(['user_id' => $user->id]);

        PatrolRoute::factory()->create(['patrol_session_id' => $patrolA->id]);
        PatrolRoute::factory()->create(['patrol_session_id' => $patrolB->id]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/patrol-routes?patrol_session_id='.$patrolA->id)
            ->assertOk();

        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($patrolA->id, $rows[0]['patrol_session_id']);
    }

    public function test_route_list_is_ordered_by_recorded_at_ascending(): void
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        PatrolRoute::factory()->create([
            'patrol_session_id' => $patrol->id,
            'recorded_at' => Carbon::parse('2026-05-20 10:05:00'),
        ]);
        PatrolRoute::factory()->create([
            'patrol_session_id' => $patrol->id,
            'recorded_at' => Carbon::parse('2026-05-20 10:01:00'),
        ]);

        $rows = $this->actingAs($user, 'api')
            ->getJson('/api/patrol-routes?patrol_session_id='.$patrol->id)
            ->json('data.data');

        $this->assertTrue(
            Carbon::parse($rows[0]['recorded_at'])->lte(Carbon::parse($rows[1]['recorded_at']))
        );
    }

    public function test_per_page_max_is_capped_at_1000(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/patrol-routes?per_page=2000')
            ->assertStatus(422);
    }

    public function test_update_and_delete_routes_are_not_available(): void
    {
        $user = User::factory()->create();
        $route = PatrolRoute::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson('/api/patrol-routes/'.$route->id, ['latitude' => 1])
            ->assertNotFound();

        $this->actingAs($user, 'api')
            ->deleteJson('/api/patrol-routes/'.$route->id)
            ->assertNotFound();
    }

    public function test_patrol_log_id_alias_maps_to_patrol_session_id(): void
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/patrol-routes', [
                'patrol_log_id' => $patrol->id,
                'latitude' => 3.14,
                'longitude' => 101.69,
            ])
            ->assertCreated()
            ->assertJsonPath('data.patrol_session_id', $patrol->id);
    }
}
