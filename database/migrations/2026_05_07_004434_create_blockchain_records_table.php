<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blockchain_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100);
            $table->string('entity_id', 36);
            $table->string('proof_type', 100);
            $table->string('canonical_version', 20)->default('v1');
            $table->string('hash_algorithm', 20)->default('sha256');
            $table->char('record_hash', 64);
            $table->json('payload_summary')->nullable();
            $table->enum('network', ['ganache', 'sepolia']);
            $table->enum('environment', ['local', 'staging', 'production']);
            $table->unsignedBigInteger('chain_id')->nullable();
            $table->string('contract_address', 255)->nullable();
            $table->string('tx_hash', 255)->nullable();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->unsignedInteger('confirmations')->default(0);
            $table->enum('status', ['pending', 'queued', 'processing', 'submitted', 'confirmed', 'failed'])->default('pending');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('proof_type');
            $table->index('status');
            $table->index(['network', 'environment']);
            $table->index('tx_hash');
            $table->index('record_hash');
            $table->unique(['entity_type', 'entity_id', 'proof_type', 'canonical_version', 'environment'], 'blockchain_records_entity_proof_env_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blockchain_records');
    }
};
