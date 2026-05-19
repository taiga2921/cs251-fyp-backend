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
        Schema::table('checkpoint_events', function (Blueprint $table) {
            $table->enum('detection_type', ['continuous', 'resume', 'manual'])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkpoint_events', function (Blueprint $table) {
            $table->enum('detection_type', ['continuous', 'resume'])->nullable()->change();
        });
    }
};
