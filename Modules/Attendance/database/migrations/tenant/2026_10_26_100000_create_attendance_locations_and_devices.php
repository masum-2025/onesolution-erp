<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATT-3: checking in from the workplace, attendance machines, offline.
 *
 * - att_locations: workplaces of a unit (and the units below it): a point
 *   in millionths of a degree (integers, never floats) and a radius in
 *   metres.
 * - att_punches: where a check-in was made (only when the unit asks for a
 *   location check), how accurate the phone was, how far from the nearest
 *   workplace, which one. The point itself is forgotten after the rule's
 *   days; the distance stays.
 * - att_device_formats: which columns of an attendance machine's file hold
 *   the employee code and the time, remembered per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('att_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('name', 120);
            $table->integer('latitude_micro');
            $table->integer('longitude_micro');
            $table->unsignedInteger('radius_m');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'unit_id']);
        });

        Schema::table('att_punches', function (Blueprint $table) {
            $table->integer('latitude_micro')->nullable();
            $table->integer('longitude_micro')->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->ulid('location_id')->nullable();
        });

        Schema::create('att_device_formats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->json('columns');
            $table->string('datetime_format', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('att_device_formats');
        Schema::table('att_punches', function (Blueprint $table) {
            $table->dropColumn(['latitude_micro', 'longitude_micro', 'accuracy_m', 'distance_m', 'location_id']);
        });
        Schema::dropIfExists('att_locations');
    }
};
