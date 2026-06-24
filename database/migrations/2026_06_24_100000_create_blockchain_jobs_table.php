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
        Schema::create('blockchain_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blockchain_record_id')->constrained('blockchain_records')->cascadeOnDelete();
            $table->enum('job_type', ['anchor', 'retry_anchor', 'verify', 'refresh_confirmation']);
            $table->enum('status', ['queued', 'processing', 'success', 'failed', 'cancelled'])->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('blockchain_record_id');
            $table->index('status');
            $table->index('job_type');
            $table->index('next_attempt_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blockchain_jobs');
    }
};
