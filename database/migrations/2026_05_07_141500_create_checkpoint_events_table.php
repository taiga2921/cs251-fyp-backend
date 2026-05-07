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
        Schema::create('checkpoint_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patrol_session_id')->constrained('patrol_sessions')->cascadeOnDelete();
            // checkpoints.id is UUID in this application (see checkpoints migration).
            $table->foreignUuid('checkpoint_id')->constrained('checkpoints')->cascadeOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->enum('detection_type', ['continuous', 'resume'])->nullable();
            $table->float('confidence_score')->default(0);
            $table->enum('status', ['pending', 'verified', 'suspicious', 'uncertain', 'rejected'])->default('pending');
            $table->timestamps();

            $table->index('patrol_session_id');
            $table->index('checkpoint_id');
            $table->index('status');
            $table->index('detected_at');
            $table->index(['patrol_session_id', 'checkpoint_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkpoint_events');
    }
};
