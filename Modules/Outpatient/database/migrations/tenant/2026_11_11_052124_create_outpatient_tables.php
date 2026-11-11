<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Outpatient (made by make:erp-module), in the client's database: one table per
 * entity, each record with its company and unit, a version and an op id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outpatient_doctors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->json('name');
            $table->string('registration_no', 30)->nullable();
            $table->string('degrees', 200)->nullable();
            $table->string('specialty', 150)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('fee_service_code', 30);
            $table->string('follow_up_service_code', 30)->nullable();
            $table->boolean('is_active')->default(false);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'outpatient_doctors_op_unique');
            $table->index(['organization_id', 'unit_id'], 'outpatient_doctors_unit_index');
        });

        Schema::create('outpatient_chambers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('name', 100);
            $table->string('room', 30)->nullable();
            $table->boolean('is_active')->default(false);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'outpatient_chambers_op_unique');
            $table->index(['organization_id', 'unit_id'], 'outpatient_chambers_unit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outpatient_chambers');
        Schema::dropIfExists('outpatient_doctors');
    }
};
