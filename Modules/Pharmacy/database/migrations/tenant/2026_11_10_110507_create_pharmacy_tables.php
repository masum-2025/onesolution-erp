<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pharmacy (made by make:erp-module), in the client's database: one table per
 * entity, each record with its company and unit, a version and an op id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_generics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->json('name');
            $table->string('drug_class', 60)->nullable();
            $table->boolean('is_controlled')->default(false);
            $table->string('controlled_schedule', 20)->nullable();
            $table->boolean('in_use')->default(false);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'pharmacy_generics_op_unique');
            $table->index(['organization_id', 'unit_id'], 'pharmacy_generics_unit_index');
        });

        Schema::create('pharmacy_manufacturers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->json('name');
            $table->string('country', 2)->nullable();
            $table->boolean('in_use')->default(false);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'pharmacy_manufacturers_op_unique');
            $table->index(['organization_id', 'unit_id'], 'pharmacy_manufacturers_unit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_manufacturers');
        Schema::dropIfExists('pharmacy_generics');
    }
};
