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
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('zone_id')
                ->constrained('zones')
                ->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('radius')->default(20);
            $table->enum('location_type', ['outdoor', 'indoor'])->default('outdoor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('zone_id');
            $table->index('is_active');
            $table->unique(['zone_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkpoints');
    }
};
