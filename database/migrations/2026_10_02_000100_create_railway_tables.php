<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->string('full_name')->nullable();
            $table->string('city');
            $table->string('state');
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->unsignedSmallInteger('platforms')->default(1);
            $table->string('image_path')->nullable();
            $table->json('facilities');
            $table->timestamps();

            $table->index(['lat', 'lng']);
        });

        Schema::create('trains', function (Blueprint $table) {
            $table->id();
            $table->string('number', 8)->unique();
            $table->string('name');
            $table->string('type', 32);
            $table->foreignId('origin_station_id')->constrained('stations');
            $table->foreignId('destination_station_id')->constrained('stations');
            $table->boolean('has_pantry')->default(false);
            $table->string('zone', 8);
            $table->string('image_path')->nullable();
            $table->json('route_geometry')->nullable();
            $table->timestamps();
        });

        Schema::create('train_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained();
            $table->unsignedSmallInteger('sequence');
            $table->time('scheduled_arrival')->nullable();
            $table->time('scheduled_departure')->nullable();
            $table->unsignedTinyInteger('day_offset')->default(0);
            $table->string('platform', 8)->nullable();
            $table->unsignedSmallInteger('distance_km');
            $table->timestamps();

            $table->unique(['train_id', 'sequence']);
            $table->index('station_id');
        });

        // Mock "live feed" — replaced by a real-time API later.
        Schema::create('train_live_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('normal'); // normal | cancelled
            $table->string('gps_status', 16)->default('active'); // active | lost
            $table->json('stop_delays'); // { "<sequence>": minutes }
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('train_live_statuses');
        Schema::dropIfExists('train_stops');
        Schema::dropIfExists('trains');
        Schema::dropIfExists('stations');
    }
};
