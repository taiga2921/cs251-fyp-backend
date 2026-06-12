<?php

namespace Tests\Feature;

use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatrolSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_store_persists_started_at_from_iso8601_utc_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12T08:30:00Z'));

        $user = User::factory()->create();
        $zone = Zone::factory()->create();
        $startedAt = '2026-06-12T00:30:00.000Z';

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/patrol-sessions', [
                'user_id' => $user->id,
                'zone_id' => $zone->id,
                'started_at' => $startedAt,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $sessionId = $response->json('data.id');
        $this->assertNotEmpty($sessionId);

        $responseStartedAt = $response->json('data.started_at');
        $this->assertIsString($responseStartedAt);
        $this->assertStringContainsString('T', $responseStartedAt);
        $this->assertTrue(
            str_ends_with($responseStartedAt, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/', $responseStartedAt) === 1,
            'API started_at must be ISO-8601 with explicit timezone offset'
        );

        $parsedResponse = Carbon::parse($responseStartedAt);
        $this->assertSame(
            Carbon::parse($startedAt)->getTimestamp(),
            $parsedResponse->getTimestamp(),
            'Response started_at must represent the same instant as the request payload'
        );

        $session = PatrolSession::query()->findOrFail($sessionId);
        $this->assertSame(
            Carbon::parse($startedAt)->getTimestamp(),
            $session->started_at?->getTimestamp(),
            'Database started_at must represent the same instant as the request payload'
        );

        Carbon::setTestNow();
    }
}
