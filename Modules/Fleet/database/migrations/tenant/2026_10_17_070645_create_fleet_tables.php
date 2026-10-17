<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fleet (made by make:erp-module), in the client's database: one table per
 * entity, each record with its company and unit, a version and an op id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('registration_no', 30);
            $table->string('model', 80)->nullable();
            $table->string('kind', 30);
            $table->bigInteger('capacity_milli')->nullable();
            $table->date('bought_on')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->boolean('in_use')->default(false);
            $table->text('notes')->nullable();
            $table->char('currency', 3)->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fleet_vehicles_op_unique');
            $table->index(['organization_id', 'unit_id'], 'fleet_vehicles_unit_index');
            $table->unique(['organization_id', 'registration_no'], 'fleet_vehicles_registration_no_unique');
            $table->index(['organization_id', 'kind'], 'fleet_vehicles_kind_index');
        });

        Schema::create('fleet_trips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('purpose', 120);
            $table->ulid('vehicle_id');
            $table->ulid('driver_id')->nullable();
            $table->date('trip_on');
            $table->string('destination', 120)->nullable();
            $table->bigInteger('distance_milli')->nullable();
            $table->unsignedBigInteger('fuel_cost_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fleet_trips_op_unique');
            $table->index(['organization_id', 'unit_id'], 'fleet_trips_unit_index');
            $table->index(['organization_id', 'vehicle_id'], 'fleet_trips_vehicle_id_index');
            $table->index(['organization_id', 'driver_id'], 'fleet_trips_driver_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_trips');
        Schema::dropIfExists('fleet_vehicles');
    }
};
