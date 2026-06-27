<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('two_factor_setup_sessions', function (Blueprint $table) {
            $table->unsignedInteger('failed_attempts')->default(0)->after('verified_at');
            $table->timestamp('locked_at')->nullable()->after('failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('two_factor_setup_sessions', function (Blueprint $table) {
            $table->dropColumn(['failed_attempts', 'locked_at']);
        });
    }
};
