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
        Schema::create('patrol_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patrol_session_id')
                ->constrained('patrol_sessions')
                ->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy')->nullable()->comment('GPS accuracy in meters');
            $table->float('altitude')->nullable()->comment('Optional altitude in meters');
            $table->timestamp('recorded_at')->comment('Device/sample time when the point was captured');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patrol_session_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patrol_routes');
    }
};
