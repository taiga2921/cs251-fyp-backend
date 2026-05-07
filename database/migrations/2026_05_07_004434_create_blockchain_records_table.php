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
            $table->index(['entity_type', 'entity_id']);
            $table->char('hash', 64);
            $table->enum('network', ['ganache', 'sepolia']);
            $table->unique(['hash', 'network']);
            $table->enum('environment', ['development', 'production']);
            $table->string('tx_hash', 255)->nullable();
            $table->bigInteger('block_number')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'failed'])->default('pending');
            $table->integer('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
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
