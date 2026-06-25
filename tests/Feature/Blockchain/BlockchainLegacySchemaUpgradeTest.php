<?php

namespace Tests\Feature\Blockchain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockchainLegacySchemaUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_provide_current_blockchain_records_columns(): void
    {
        $this->assertTrue(Schema::hasTable('blockchain_records'));
        $this->assertTrue(Schema::hasColumns('blockchain_records', [
            'proof_type',
            'record_hash',
            'canonical_version',
            'hash_algorithm',
            'payload_summary',
            'chain_id',
            'contract_address',
            'confirmations',
            'last_error',
        ]));
    }

    public function test_upgrade_migration_upgrades_legacy_blockchain_records_schema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('blockchain_verifications');
        Schema::dropIfExists('blockchain_jobs');
        Schema::dropIfExists('blockchain_records');
        Schema::enableForeignKeyConstraints();

        Schema::create('blockchain_records', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100);
            $table->string('entity_id', 36);
            $table->char('hash', 64);
            $table->string('network');
            $table->string('environment');
            $table->string('tx_hash', 255)->nullable();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->string('status')->default('confirmed');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        $legacyId = (string) Str::uuid();
        $entityId = (string) Str::uuid();
        $legacyHash = str_repeat('a', 64);

        DB::table('blockchain_records')->insert([
            'id' => $legacyId,
            'entity_type' => 'anpr_event',
            'entity_id' => $entityId,
            'hash' => $legacyHash,
            'network' => 'sepolia',
            'environment' => 'development',
            'tx_hash' => '0x'.str_repeat('1', 64),
            'block_number' => 12345,
            'status' => 'confirmed',
            'retry_count' => 2,
            'error_message' => 'legacy rpc timeout',
            'submitted_at' => now()->subHour(),
            'confirmed_at' => now()->subMinutes(30),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subMinutes(30),
        ]);

        $this->runUpgradeMigration();

        $this->assertTrue(Schema::hasColumn('blockchain_records', 'proof_type'));
        $this->assertTrue(Schema::hasColumn('blockchain_records', 'record_hash'));
        $this->assertTrue(Schema::hasColumn('blockchain_records', 'payload_summary'));
        $this->assertTrue(Schema::hasColumn('blockchain_records', 'confirmations'));
        $this->assertTrue(Schema::hasColumn('blockchain_records', 'last_error'));
        $this->assertFalse(Schema::hasColumn('blockchain_records', 'hash'));
        $this->assertFalse(Schema::hasColumn('blockchain_records', 'error_message'));

        $row = DB::table('blockchain_records')->where('id', $legacyId)->first();

        $this->assertNotNull($row);
        $this->assertSame($legacyHash, $row->record_hash);
        $this->assertSame('legacy', $row->proof_type);
        $this->assertSame('v1', $row->canonical_version);
        $this->assertSame('sha256', $row->hash_algorithm);
        $this->assertSame('local', $row->environment);
        $this->assertSame('confirmed', $row->status);
        $this->assertSame(0, (int) $row->confirmations);
        $this->assertSame('legacy rpc timeout', $row->last_error);
        $this->assertSame('0x'.str_repeat('1', 64), $row->tx_hash);
        $this->assertSame(12345, (int) $row->block_number);
        $this->assertSame(2, (int) $row->retry_count);
        $this->assertNull($row->chain_id);
        $this->assertNull($row->contract_address);
        $this->assertNull($row->payload_summary);
    }

    public function test_upgrade_migration_is_idempotent_on_current_schema(): void
    {
        $this->runUpgradeMigration();
        $this->runUpgradeMigration();

        $this->assertTrue(Schema::hasColumns('blockchain_records', [
            'proof_type',
            'record_hash',
            'payload_summary',
            'confirmations',
            'last_error',
        ]));
    }

    private function runUpgradeMigration(): void
    {
        $migration = require database_path('migrations/2026_06_25_120000_upgrade_legacy_blockchain_records_schema.php');
        $migration->up();
    }
}
