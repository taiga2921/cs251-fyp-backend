<?php

namespace Tests\Unit\Blockchain;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Models\User;
use App\Services\Blockchain\BlockchainHashService;
use App\Services\Blockchain\BlockchainRetryService;
use App\Services\Blockchain\BlockchainVerificationService;
use App\Services\Blockchain\EthereumRpcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockchainVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BlockchainVerificationService $service;

    private string $imageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRoot = storage_path('framework/testing/blockchain-verify');
        File::ensureDirectoryExists($this->imageRoot);
        config(['anpr.image_roots' => [$this->imageRoot]]);

        config([
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.private_key' => '0x'.str_repeat('d', 64),
        ]);

        $this->service = new BlockchainVerificationService(
            app(BlockchainHashService::class),
            new EthereumRpcClient(new BlockchainRetryService),
            new BlockchainRetryService,
        );
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->imageRoot)) {
            File::deleteDirectory($this->imageRoot);
        }

        parent::tearDown();
    }

    public function test_confirmed_record_with_matching_hash_and_onchain_true_returns_valid(): void
    {
        $this->fakeVerifyRpc(found: true);

        $record = $this->createConfirmedAnprRecord();

        $verification = $this->service->verify($record);

        $this->assertSame('valid', $verification->result);
        $this->assertTrue($verification->onchain_found);
        $this->assertSame($record->record_hash, $verification->stored_hash);
        $this->assertSame($record->record_hash, $verification->recomputed_hash);
        $this->assertSame($record->record_hash, $verification->onchain_hash);
    }

    public function test_confirmed_record_with_changed_source_entity_returns_tampered(): void
    {
        Http::fake();

        $record = $this->createConfirmedAnprRecord();
        $event = AnprEvent::query()->findOrFail($record->entity_id);
        $event->update(['plate_number' => 'TAMPERED']);

        $verification = $this->service->verify($record);

        $this->assertSame('tampered', $verification->result);
        $this->assertNotSame($record->record_hash, $verification->recomputed_hash);
        Http::assertNothingSent();
    }

    public function test_non_confirmed_record_returns_pending_without_rpc(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->queued()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => AnprEvent::factory()->create()->id,
            'record_hash' => hash('sha256', 'pending-demo'),
        ]);

        $verification = $this->service->verify($record);

        $this->assertSame('pending', $verification->result);
        $this->assertNull($verification->recomputed_hash);
        $this->assertNull($verification->onchain_found);
        Http::assertNothingSent();
    }

    public function test_confirmed_record_with_matching_hash_and_onchain_false_returns_onchain_missing(): void
    {
        $this->fakeVerifyRpc(found: false);

        $record = $this->createConfirmedAnprRecord();

        $verification = $this->service->verify($record);

        $this->assertSame('onchain_missing', $verification->result);
        $this->assertFalse($verification->onchain_found);
        $this->assertNull($verification->onchain_hash);
    }

    public function test_missing_source_entity_returns_failed(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => '01940000-0000-7000-8000-000000000999',
            'record_hash' => hash('sha256', 'missing-entity'),
        ]);

        $verification = $this->service->verify($record);

        $this->assertSame('failed', $verification->result);
        $this->assertStringContainsString('no longer exists', (string) $verification->error_message);
        Http::assertNothingSent();
    }

    public function test_unsupported_entity_type_returns_failed(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'patrol_session',
            'entity_id' => '01940000-0000-7000-8000-000000000101',
            'record_hash' => hash('sha256', 'unsupported'),
        ]);

        $verification = $this->service->verify($record);

        $this->assertSame('failed', $verification->result);
        $this->assertStringContainsString('Unsupported blockchain entity type', (string) $verification->error_message);
        Http::assertNothingSent();
    }

    public function test_rpc_failure_returns_failed_with_sanitized_error(): void
    {
        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Connection refused at http://127.0.0.1:7545 key='.config('blockchain.private_key'),
                ],
            ]),
        ]);

        $record = $this->createConfirmedAnprRecord();

        $verification = $this->service->verify($record);

        $this->assertSame('failed', $verification->result);
        $this->assertStringNotContainsString('http://127.0.0.1:7545', (string) $verification->error_message);
        $this->assertStringNotContainsString((string) config('blockchain.private_key'), (string) $verification->error_message);
        $this->assertStringContainsString('[rpc-url-redacted]', (string) $verification->error_message);
    }

    public function test_verification_row_stores_verified_by_for_manual_verification(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'pending-manual'),
        ]);

        $verification = $this->service->verify($record, 'manual', $user);

        $this->assertSame($user->id, $verification->verified_by);
        $this->assertSame('manual', $verification->verification_type);
    }

    #[DataProvider('validVerificationTypeProvider')]
    public function test_verify_accepts_valid_verification_types(string $verificationType): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'valid-type-'.$verificationType),
        ]);

        $verification = $this->service->verify($record, $verificationType);

        $this->assertSame($verificationType, $verification->verification_type);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validVerificationTypeProvider(): array
    {
        return [
            'manual' => ['manual'],
            'scheduled' => ['scheduled'],
            'api' => ['api'],
            'system' => ['system'],
        ];
    }

    public function test_verify_rejects_invalid_verification_type(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'invalid-type'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid blockchain verification type.');

        $this->service->verify($record, 'bogus');
    }

    public function test_invalid_verification_type_does_not_create_job_or_verification_rows(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'no-rows'),
        ]);

        try {
            $this->service->verify($record, 'invalid');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseMissing('blockchain_jobs', [
            'blockchain_record_id' => $record->id,
            'job_type' => 'verify',
        ]);

        $this->assertDatabaseMissing('blockchain_verifications', [
            'blockchain_record_id' => $record->id,
        ]);
    }

    public function test_verify_job_row_is_created_with_job_type_verify(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'job-row'),
        ]);

        $this->service->verify($record);

        $this->assertDatabaseHas('blockchain_jobs', [
            'blockchain_record_id' => $record->id,
            'job_type' => 'verify',
            'attempts' => 1,
            'max_attempts' => 1,
        ]);
    }

    public function test_verify_job_row_becomes_success_for_pending_result(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'pending-job'),
        ]);

        $this->service->verify($record);

        $this->assertVerifyJobSucceeded($record);
    }

    public function test_verify_job_row_becomes_success_for_tampered_result(): void
    {
        Http::fake();

        $record = $this->createConfirmedAnprRecord();
        AnprEvent::query()->findOrFail($record->entity_id)->update(['plate_number' => 'CHANGED']);

        $this->service->verify($record);

        $this->assertVerifyJobSucceeded($record);
    }

    public function test_verify_job_row_becomes_success_for_onchain_missing_result(): void
    {
        $this->fakeVerifyRpc(found: false);

        $record = $this->createConfirmedAnprRecord();

        $this->service->verify($record);

        $this->assertVerifyJobSucceeded($record);
    }

    public function test_verify_job_row_becomes_success_for_valid_result(): void
    {
        $this->fakeVerifyRpc(found: true);

        $record = $this->createConfirmedAnprRecord();

        $this->service->verify($record);

        $this->assertVerifyJobSucceeded($record);
    }

    public function test_verify_job_row_becomes_failed_for_failed_verification(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'patrol_session',
            'entity_id' => '01940000-0000-7000-8000-000000000202',
            'record_hash' => hash('sha256', 'failed-job'),
        ]);

        $this->service->verify($record);

        $job = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'verify')
            ->first();

        $this->assertNotNull($job);
        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->finished_at);
    }

    public function test_confirmed_anpr_image_record_with_matching_hash_returns_valid(): void
    {
        $this->fakeVerifyRpc(found: true);

        $record = $this->createConfirmedAnprImageRecord();

        $verification = $this->service->verify($record);

        $this->assertSame('valid', $verification->result);
        $this->assertSame($record->record_hash, $verification->recomputed_hash);
    }

    public function test_missing_anpr_image_entity_returns_failed(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => '01940000-0000-7000-8000-000000000888',
            'proof_type' => 'evidence_file',
            'record_hash' => hash('sha256', 'missing-image'),
        ]);

        $verification = $this->service->verify($record);

        $this->assertSame('failed', $verification->result);
        $this->assertStringContainsString('no longer exists', (string) $verification->error_message);
        Http::assertNothingSent();
    }

    public function test_anpr_image_with_unresolvable_file_recomputes_metadata_hash_deterministically(): void
    {
        $this->fakeVerifyRpc(found: true);

        $image = AnprImage::factory()->create([
            'file_path' => '../outside/evidence.jpg',
            'file_size' => 1024,
            'resolution' => '640x480',
        ]);

        $hash = app(BlockchainHashService::class)->hashEntity($image, 'evidence_file');

        $record = BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'record_hash' => $hash['record_hash'],
            'contract_address' => '0x'.str_repeat('a', 40),
        ]);

        $verification = $this->service->verify($record);

        $this->assertSame('valid', $verification->result);
        $this->assertSame($hash['record_hash'], $verification->recomputed_hash);
    }

    private function assertVerifyJobSucceeded(BlockchainRecord $record): void
    {
        $job = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'verify')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($job);
        $this->assertSame('success', $job->status);
        $this->assertNotNull($job->finished_at);
    }

    private function createConfirmedAnprRecord(): BlockchainRecord
    {
        $event = AnprEvent::factory()->create([
            'plate_number' => 'VERIFY01',
            'confidence' => 0.9100,
        ]);

        $hash = app(BlockchainHashService::class)->hashEntity($event);

        return BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => $event->id,
            'proof_type' => 'entity_created',
            'canonical_version' => 'v1',
            'hash_algorithm' => 'sha256',
            'record_hash' => $hash['record_hash'],
            'contract_address' => '0x'.str_repeat('a', 40),
        ]);
    }

    private function createConfirmedAnprImageRecord(): BlockchainRecord
    {
        $relativePath = 'evidence/verify-image.jpg';
        $absolutePath = $this->imageRoot.DIRECTORY_SEPARATOR.$relativePath;
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'verify-image-bytes');

        $image = AnprImage::factory()->create([
            'file_path' => $relativePath,
            'file_size' => 18,
            'resolution' => '640x480',
        ]);

        $hash = app(BlockchainHashService::class)->hashEntity($image, 'evidence_file');

        return BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'canonical_version' => 'v1',
            'hash_algorithm' => 'sha256',
            'record_hash' => $hash['record_hash'],
            'contract_address' => '0x'.str_repeat('a', 40),
        ]);
    }

    private function fakeVerifyRpc(bool $found): void
    {
        Http::fake(function ($request) use ($found) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $found
                        ? '0x'.str_repeat('0', 63).'1'
                        : '0x'.str_repeat('0', 64),
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });
    }
}
