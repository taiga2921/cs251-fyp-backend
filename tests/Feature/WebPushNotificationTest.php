<?php

namespace Tests\Feature;

use App\Models\PatrolSession;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PatrolPushNotificationService;
use App\Services\WebPushDeliveryResult;
use App\Services\WebPushNotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_test_push_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/push-notifications/test')
            ->assertUnauthorized();
    }

    public function test_test_push_endpoint_returns_safe_response_when_not_configured(): void
    {
        config([
            'webpush.vapid.public_key' => '',
            'webpush.vapid.private_key' => '',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/push-notifications/test')
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_test_push_endpoint_returns_422_when_user_has_no_subscriptions(): void
    {
        config([
            'webpush.vapid.public_key' => 'test-public-key',
            'webpush.vapid.private_key' => 'test-private-key',
            'webpush.vapid.subject' => 'mailto:test@example.com',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/push-notifications/test')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_patrol_completion_does_not_fail_when_push_sending_throws(): void
    {
        $webPush = Mockery::mock(WebPushNotificationService::class);
        $webPush->shouldReceive('sendToAdmins')
            ->once()
            ->andThrow(new \RuntimeException('Simulated push failure'));

        $service = new PatrolPushNotificationService($webPush);
        $session = PatrolSession::factory()->create(['status' => 'completed']);

        $service->sessionCompleted($session);

        $this->assertTrue(true);
    }

    public function test_send_test_to_user_returns_failure_result_when_push_throws(): void
    {
        $webPush = Mockery::mock(WebPushNotificationService::class);
        $webPush->shouldReceive('sendToUser')
            ->once()
            ->andThrow(new \RuntimeException('Simulated push failure'));

        $service = new PatrolPushNotificationService($webPush);
        $user = User::factory()->create();

        $result = $service->sendTestToUser($user, 'Test', 'Body');

        $this->assertInstanceOf(WebPushDeliveryResult::class, $result);
        $this->assertGreaterThan(0, $result->failed);
    }

    public function test_expired_subscription_can_be_removed(): void
    {
        $user = User::factory()->create();
        $subscription = PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.test/subscription-1',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ]);

        $subscription->delete();

        $this->assertNull(PushSubscription::query()->find($subscription->id));
    }
}
