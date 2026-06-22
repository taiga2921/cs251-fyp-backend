<?php

namespace Tests\Feature;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\Camera;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class AnprMonitoringTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    private string $imageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->imageRoot = storage_path('framework/testing/anpr-images');
        File::ensureDirectoryExists($this->imageRoot);
        config(['anpr.image_roots' => [$this->imageRoot]]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->imageRoot)) {
            File::deleteDirectory($this->imageRoot);
        }

        parent::tearDown();
    }

    public function test_anpr_events_index_supports_plate_number_filter(): void
    {
        $admin = $this->adminUser();
        $matching = AnprEvent::factory()->create(['plate_number' => 'ABC-1001']);
        AnprEvent::factory()->create(['plate_number' => 'XYZ-9999']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?plate_number=ABC')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $matching->id);
    }

    public function test_anpr_events_index_supports_validity_and_flagged_filters(): void
    {
        $admin = $this->adminUser();
        $target = AnprEvent::factory()->create(['is_valid' => true, 'is_flagged' => true]);
        AnprEvent::factory()->create(['is_valid' => false, 'is_flagged' => false]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?is_valid=1&is_flagged=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);
    }

    public function test_anpr_events_index_hides_camera_credentials(): void
    {
        $admin = $this->adminUser();
        $camera = Camera::factory()->create([
            'username' => 'camera-user',
            'password' => 'camera-secret',
            'rtsp_url' => 'rtsp://camera-user:camera-secret@192.168.1.10:554/stream',
        ]);
        AnprEvent::factory()->create(['camera_id' => $camera->id]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events')
            ->assertOk();

        $cameraPayload = $response->json('data.data.0.camera');

        $this->assertIsArray($cameraPayload);
        $this->assertArrayNotHasKey('password', $cameraPayload);
        $this->assertArrayNotHasKey('username', $cameraPayload);
        $this->assertArrayNotHasKey('rtsp_url', $cameraPayload);
        $this->assertSame('camera-user', $camera->username);
    }

    public function test_anpr_image_file_requires_authentication(): void
    {
        $image = AnprImage::factory()->create();

        $this->getJson('/api/anpr-images/'.$image->id.'/file')
            ->assertUnauthorized();
    }

    public function test_anpr_image_file_rejects_unavailable_paths(): void
    {
        $admin = $this->adminUser();
        $image = AnprImage::factory()->create([
            'file_path' => '../outside-root/evidence.jpg',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-images/'.$image->id.'/file')
            ->assertNotFound();
    }

    public function test_anpr_image_file_serves_allowed_file(): void
    {
        $admin = $this->adminUser();
        $relativePath = 'evidence/sample.jpg';
        $absolutePath = $this->imageRoot.DIRECTORY_SEPARATOR.$relativePath;
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'fake-image-bytes');

        $image = AnprImage::factory()->create([
            'file_path' => $relativePath,
        ]);

        $this->actingAs($admin, 'api')
            ->get('/api/anpr-images/'.$image->id.'/file')
            ->assertOk();
    }

    public function test_anpr_image_resource_includes_file_url_when_resolvable(): void
    {
        $admin = $this->adminUser();
        $relativePath = 'evidence/with-url.jpg';
        $absolutePath = $this->imageRoot.DIRECTORY_SEPARATOR.$relativePath;
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'fake-image-bytes');

        $image = AnprImage::factory()->create([
            'file_path' => $relativePath,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-images/'.$image->id)
            ->assertOk()
            ->assertJsonPath('data.url', url('/api/anpr-images/'.$image->id.'/file'))
            ->assertJsonPath('data.image_url', url('/api/anpr-images/'.$image->id.'/file'));
    }
}
