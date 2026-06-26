<?php

namespace Tests\Feature\Blockchain;

use App\Models\BlockchainRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class BlockchainMonitoringApiTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_load_blockchain_summary(): void
    {
        $admin = $this->adminUser();

        BlockchainRecord::factory()->pending()->create(['network' => 'ganache', 'environment' => 'local']);
        BlockchainRecord::factory()->confirmed()->create(['network' => 'sepolia', 'environment' => 'staging']);
        BlockchainRecord::factory()->failed()->create(['network' => 'ganache', 'environment' => 'local']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.confirmed', 1)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonMissing(['data.rpc_url' => true])
            ->assertJsonMissing(['data.private_key' => true]);
    }

    public function test_security_operator_can_load_blockchain_summary(): void
    {
        $operator = $this->securityOperatorUser();

        BlockchainRecord::factory()->queued()->create();

        $this->actingAs($operator, 'api')
            ->getJson('/api/blockchain-records/summary')
            ->assertOk()
            ->assertJsonPath('data.queued', 1);
    }

    public function test_guard_cannot_load_blockchain_summary(): void
    {
        $guard = $this->guardUser();

        $this->actingAs($guard, 'api')
            ->getJson('/api/blockchain-records/summary')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Only administrators and security operators may perform this action.'
            );
    }

    public function test_search_by_record_hash_works(): void
    {
        $admin = $this->adminUser();

        $matching = BlockchainRecord::factory()->pending()->create([
            'record_hash' => '0xabc123def4567890abcdef1234567890abcdef1234567890abcdef1234567890',
        ]);
        BlockchainRecord::factory()->pending()->create([
            'record_hash' => '0x9999999999999999999999999999999999999999999999999999999999999999',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records?search=abc123def456')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_search_by_tx_hash_works(): void
    {
        $admin = $this->adminUser();

        $matching = BlockchainRecord::factory()->submitted()->create([
            'tx_hash' => '0xfeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface',
        ]);
        BlockchainRecord::factory()->submitted()->create([
            'tx_hash' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records?search=feedfacefeedface')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_summary_counts_statuses_correctly(): void
    {
        $admin = $this->adminUser();

        BlockchainRecord::factory()->pending()->create();
        BlockchainRecord::factory()->queued()->create();
        BlockchainRecord::factory()->create(['status' => 'processing']);
        BlockchainRecord::factory()->submitted()->create();
        BlockchainRecord::factory()->confirmed()->create();
        BlockchainRecord::factory()->failed()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.queued', 1)
            ->assertJsonPath('data.processing', 1)
            ->assertJsonPath('data.submitted', 1)
            ->assertJsonPath('data.confirmed', 1)
            ->assertJsonPath('data.failed', 1);
    }

    public function test_entity_type_filter_without_entity_id_works(): void
    {
        $admin = $this->adminUser();

        $matching = BlockchainRecord::factory()->pending()->create(['entity_type' => 'anpr_event']);
        BlockchainRecord::factory()->pending()->create(['entity_type' => 'patrol_session']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records?entity_type=anpr_event')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }
}
