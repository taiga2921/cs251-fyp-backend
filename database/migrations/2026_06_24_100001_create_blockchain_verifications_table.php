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
        Schema::create('blockchain_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blockchain_record_id')->constrained('blockchain_records')->cascadeOnDelete();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('verification_type', ['manual', 'scheduled', 'api', 'system']);
            $table->char('stored_hash', 64);
            $table->char('recomputed_hash', 64)->nullable();
            $table->char('onchain_hash', 64)->nullable();
            $table->boolean('onchain_found')->nullable();
            $table->enum('result', ['valid', 'tampered', 'pending', 'failed', 'onchain_missing']);
            $table->text('error_message')->nullable();
            $table->timestamp('verified_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('blockchain_record_id');
            $table->index('verified_by');
            $table->index('result');
            $table->index('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blockchain_verifications');
    }
};
