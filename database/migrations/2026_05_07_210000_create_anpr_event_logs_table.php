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
        Schema::create('anpr_event_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('anpr_event_id')->constrained('anpr_events')->cascadeOnDelete();
            $table->string('stage', 50);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('anpr_event_id');
            $table->index('stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anpr_event_logs');
    }
};
