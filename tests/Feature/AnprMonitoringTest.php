<?php

namespace Tests\Feature;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\Camera;
use App\Services\Anpr\AnprImageFileService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        config([
            'anpr.image_roots' => [$this->imageRoot],
            'blockchain.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->imageRoot)) {
            File::deleteDirectory($this->imageRoot);
        }

        parent::tearDown();
    }

    public function test_anpr_events_index_returns_latest_detections_first_by_default(): void
    {
        $admin = $this->adminUser();
        $older = AnprEvent::factory()->create([
            'detection_time' => now()->subHours(2),
        ]);
        $newer = AnprEvent::factory()->create([
            'detection_time' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.id', $newer->id)
            ->assertJsonPath('data.data.1.id', $older->id);
    }

    public function test_anpr_events_index_supports_sort_and_direction(): void
    {
        $admin = $this->adminUser();
        $eventA = AnprEvent::factory()->create([
            'detection_time' => now()->subHours(2),
        ]);
        $eventB = AnprEvent::factory()->create([
            'detection_time' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?sort=detection_time&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $eventB->id)
            ->assertJsonPath('data.data.1.id', $eventA->id);
    }

    public function test_anpr_events_index_supports_per_page(): void
    {
        $admin = $this->adminUser();
        AnprEvent::factory()->count(12)->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonCount(10, 'data.data');
    }

    public function test_anpr_events_index_supports_since_filter(): void
    {
        $admin = $this->adminUser();
        $cutoff = now()->subMinutes(30);

        $older = AnprEvent::factory()->create([
            'detection_time' => now()->subHours(3),
        ]);
        DB::table('anpr_events')->where('id', $older->id)->update([
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $newer = AnprEvent::factory()->create([
            'detection_time' => now()->subMinutes(5),
        ]);
        DB::table('anpr_events')->where('id', $newer->id)->update([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?since='.urlencode($cutoff->toIso8601String()))
            ->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($newer->id, $ids);
        $this->assertNotContains($older->id, $ids);
    }

    public function test_anpr_events_index_rejects_invalid_sort(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?sort=invalid_field')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('sort', $response->json('data.errors'));
    }

    public function test_anpr_events_index_rejects_invalid_direction(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events?direction=sideways')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('direction', $response->json('data.errors'));
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
            'name' => 'Gate Camera',
            'location' => 'Gate A',
            'ip_address' => '192.168.1.10',
            'port' => 554,
            'username' => 'camera-user',
            'password' => 'camera-secret',
            'rtsp_url' => 'rtsp://camera-user:camera-secret@192.168.1.10:554/stream',
        ]);
        $event = AnprEvent::factory()->create(['camera_id' => $camera->id]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events')
            ->assertOk();

        $cameraPayload = $response->json('data.data.0.camera');

        $this->assertIsArray($cameraPayload);
        $this->assertArrayNotHasKey('password', $cameraPayload);
        $this->assertArrayNotHasKey('username', $cameraPayload);
        $this->assertArrayNotHasKey('rtsp_url', $cameraPayload);
        $this->assertArrayNotHasKey('ip_address', $cameraPayload);
        $this->assertArrayNotHasKey('port', $cameraPayload);
        $this->assertSame('Gate Camera', $cameraPayload['name']);
        $this->assertSame('Gate A', $cameraPayload['location']);

        $showResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events/'.$event->id)
            ->assertOk();

        $showCamera = $showResponse->json('data.camera');
        $this->assertIsArray($showCamera);
        $showResponse
            ->assertJsonMissingPath('data.camera.ip_address')
            ->assertJsonMissingPath('data.camera.port')
            ->assertJsonMissingPath('data.camera.username')
            ->assertJsonMissingPath('data.camera.password')
            ->assertJsonMissingPath('data.camera.rtsp_url')
            ->assertJsonPath('data.camera.name', 'Gate Camera');
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

    public function test_anpr_event_image_upload_stores_file_and_creates_row(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('full.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.image_type', 'full')
            ->assertJsonPath('data.anpr_event_id', $event->id);

        $imageId = $response->json('data.id');
        $filePath = $response->json('data.file_path');
        $this->assertIsString($filePath);
        $this->assertStringStartsWith('events/'.$event->id.'/', $filePath);

        $absolutePath = $this->imageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $filePath);
        $this->assertFileExists($absolutePath);

        $this->assertDatabaseHas('anpr_images', [
            'id' => $imageId,
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => $filePath,
        ]);
    }

    public function test_anpr_event_image_upload_returns_file_urls(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('plate.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'plate',
                'image' => $file,
            ])
            ->assertOk();

        $imageId = $response->json('data.id');
        $expectedUrl = url('/api/anpr-images/'.$imageId.'/file');

        $response
            ->assertJsonPath('data.url', $expectedUrl)
            ->assertJsonPath('data.image_url', $expectedUrl);
    }

    public function test_anpr_event_image_upload_serves_uploaded_file(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('annotated.jpg', 200, 'image/jpeg');

        $uploadResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'annotated',
                'image' => $file,
            ])
            ->assertOk();

        $imageId = $uploadResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->get('/api/anpr-images/'.$imageId.'/file')
            ->assertOk();
    }

    public function test_anpr_event_image_upload_replaces_existing_image_type(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $firstFile = UploadedFile::fake()->create('full-first.jpg', 200, 'image/jpeg');
        $secondFile = UploadedFile::fake()->create('full-second.jpg', 200, 'image/jpeg');

        $firstResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $firstFile,
            ])
            ->assertOk();

        $firstId = $firstResponse->json('data.id');
        $firstPath = $firstResponse->json('data.file_path');
        $firstAbsolute = $this->imageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $firstPath);

        $secondResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $secondFile,
            ])
            ->assertOk();

        $secondId = $secondResponse->json('data.id');
        $secondPath = $secondResponse->json('data.file_path');

        $this->assertSame($firstId, $secondId);
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertDatabaseCount('anpr_images', 1);
        $this->assertFileDoesNotExist($firstAbsolute);

        $secondAbsolute = $this->imageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $secondPath);
        $this->assertFileExists($secondAbsolute);
    }

    public function test_anpr_event_image_upload_rejects_invalid_image_type(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('invalid.jpg', 200, 'image/jpeg');

        $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'thumbnail',
                'image' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_anpr_image_file_service_rejects_path_traversal_on_delete(): void
    {
        $service = app(AnprImageFileService::class);

        $this->assertFalse($service->deleteIfWithinAllowedRoots('../outside-root/evidence.jpg'));
        $this->assertNull($service->resolveAbsolutePath('../outside-root/evidence.jpg'));
    }
}
