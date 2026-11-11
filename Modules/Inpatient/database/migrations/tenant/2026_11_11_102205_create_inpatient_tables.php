<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inpatient (made by make:erp-module), in the client's database: one table per
 * entity, each record with its company and unit, a version and an op id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inpatient_wards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->json('name');
            $table->string('kind', 30);
            $table->boolean('is_active')->default(false);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'inpatient_wards_op_unique');
            $table->index(['organization_id', 'unit_id'], 'inpatient_wards_unit_index');
            $table->index(['organization_id', 'kind'], 'inpatient_wards_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inpatient_wards');
    }
};
