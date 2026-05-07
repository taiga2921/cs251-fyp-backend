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
        Schema::create('checkpoint_event_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('checkpoint_event_id')->unique()->constrained('checkpoint_events')->cascadeOnDelete();
            $table->decimal('distance_score', 5, 2)->default(0);
            $table->decimal('accuracy_score', 5, 2)->default(0);
            $table->decimal('time_score', 5, 2)->default(0);
            $table->decimal('stability_score', 5, 2)->default(0);
            $table->decimal('gap_factor', 4, 2)->default(1.00);
            $table->decimal('integrity_factor', 4, 2)->default(1.00);
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkpoint_event_metrics');
    }
};
