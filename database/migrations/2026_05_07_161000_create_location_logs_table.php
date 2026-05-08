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
        Schema::create('location_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patrol_session_id')->constrained('patrol_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy')->comment('GPS accuracy in meters');
            $table->unsignedBigInteger('timestamp')->comment('Device timestamp in milliseconds');
            $table->timestamp('server_received_at')->nullable()->comment('Backend received time');
            $table->enum('source', ['live', 'resume', 'sync']);
            $table->enum('tracking_state', ['active', 'resumed', 'offline']);
            $table->float('speed')->nullable()->comment('Meters per second');
            $table->float('heading')->nullable()->comment('Degrees 0-360');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patrol_session_id', 'timestamp']);
            $table->index(['user_id', 'timestamp']);
            $table->index('source');
            $table->index('tracking_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_logs');
    }
};
