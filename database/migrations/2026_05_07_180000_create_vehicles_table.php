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
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('plate_number', 20)->unique();
            $table->string('owner_name', 100)->nullable();
            $table->string('vehicle_type', 50)->nullable();
            $table->enum('status', ['normal', 'flagged', 'whitelist'])->default('normal');
            $table->enum('source', ['manual', 'auto_detected'])->default('manual');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('plate_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
