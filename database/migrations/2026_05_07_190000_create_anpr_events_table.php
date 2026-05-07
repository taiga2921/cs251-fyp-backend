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
        Schema::create('anpr_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignUuid('camera_id')->constrained('cameras')->restrictOnDelete();
            $table->foreignUuid('blockchain_record_id')->nullable()->constrained('blockchain_records')->nullOnDelete();
            $table->string('plate_number', 20);
            $table->decimal('confidence', 5, 4)->default(0.0000);
            $table->timestamp('detection_time');
            $table->boolean('is_flagged')->default(false);
            $table->boolean('is_valid')->default(true);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index('plate_number');
            $table->index('detection_time');
            $table->index('is_flagged');
            $table->index('is_valid');
            $table->index('camera_id');
            $table->index('vehicle_id');
            $table->index('blockchain_record_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anpr_events');
    }
};
