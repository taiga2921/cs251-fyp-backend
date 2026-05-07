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
        Schema::create('anpr_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('anpr_event_id')->constrained('anpr_events')->cascadeOnDelete();
            $table->enum('image_type', ['full', 'plate', 'annotated']);
            $table->string('file_path', 255);
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('anpr_event_id');
            $table->index('image_type');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anpr_images');
    }
};
