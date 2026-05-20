<?php

namespace Tests\Feature;

use App\Events\Patrol\PatrolCheckpointSuspicious;
use App\Events\Patrol\PatrolRouteUpdated;
use App\Events\Patrol\PatrolSessionStarted;
use App\Events\Patrol\PatrolValidationCompleted;
use App\Models\CheckpointEvent;
use App\Models\PatrolRoute;
use App\Models\PatrolSession;
use App\Models\User;
use App\Services\PatrolBroadcastService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PatrolBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_session_started_dispatches_when_broadcasting_enabled(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Event::fake([PatrolSessionStarted::class]);

        $session = PatrolSession::factory()->create();
        app(PatrolBroadcastService::class)->sessionStarted($session);

        Event::assertDispatched(PatrolSessionStarted::class);
    }

    public function test_route_creation_does_not_fail_when_broadcasting_disabled(): void
    {
        Config::set('broadcasting.default', 'null');
        Event::fake();

        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/patrol-routes', [
                'patrol_session_id' => $patrol->id,
                'latitude' => 3.139,
                'longitude' => 101.6869,
            ])
            ->assertCreated();

        Event::assertNotDispatched(PatrolRouteUpdated::class);
    }

    public function test_validation_completion_does_not_fail_when_broadcasting_disabled(): void
    {
        Config::set('broadcasting.default', 'null');
        Event::fake();

        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/patrol-sessions/'.$patrol->id.'/validate')
            ->assertOk();

        Event::assertNotDispatched(PatrolValidationCompleted::class);
    }

    public function test_checkpoint_suspicious_broadcast_does_not_fail_when_broadcasting_disabled(): void
    {
        Config::set('broadcasting.default', 'null');
        Event::fake();

        $patrol = PatrolSession::factory()->create();
        $event = CheckpointEvent::factory()->create([
            'patrol_session_id' => $patrol->id,
            'status' => 'suspicious',
        ]);

        app(PatrolBroadcastService::class)->checkpointUpdated($event->fresh());

        Event::assertNotDispatched(PatrolCheckpointSuspicious::class);
    }

    public function test_route_updated_service_does_not_throw_when_broadcasting_disabled(): void
    {
        Config::set('broadcasting.default', 'null');

        $route = PatrolRoute::factory()->create();

        app(PatrolBroadcastService::class)->routeUpdated($route);

        $this->assertTrue(true);
    }
}
