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
        Schema::create('patrol_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignUuid('zone_id')
                ->constrained('zones')
                ->restrictOnDelete();
            $table->foreignUuid('blockchain_record_id')
                ->nullable()
                ->constrained('blockchain_records')
                ->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', ['active', 'completed', 'aborted'])->default('active');
            $table->timestamps();

            $table->index('user_id');
            $table->index('zone_id');
            $table->index('blockchain_record_id');
            $table->index('status');
            $table->index(['user_id', 'zone_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patrol_sessions');
    }
};
