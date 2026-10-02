<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The station directory (database/data/station_directory.json) has identity and
 * location for thousands of stations but no city, platform or facility data:
 * those become optional (null = unknown), and directory metadata is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->unsignedSmallInteger('platforms')->nullable()->default(null)->change();
            $table->json('facilities')->nullable()->change();
            $table->string('zone', 8)->nullable()->after('state');
            $table->boolean('is_active')->default(true)->after('facilities');
            $table->json('aliases')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['zone', 'is_active', 'aliases']);
        });
    }
};
