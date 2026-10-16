<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-3a, in the client's database:
 * - fac_operations: a style's operation bulletin, each sewing job with its
 *   standard minutes (thousandths of a minute), machine and the piece rate
 *   an operator is paid for it (minor units of the company's currency;
 *   used from FAC-3c). Replaced as a whole, like the bill of materials.
 * - fac_line_days: who worked on a line on a day (operators, helpers) and
 *   for how many minutes; the base of its efficiency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('style_id');
            $table->string('name', 120);
            $table->string('machine', 40)->nullable();
            $table->unsignedInteger('smv_milli');
            $table->unsignedBigInteger('piece_rate_minor')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'style_id'], 'fac_operations_style_index');
        });

        Schema::create('fac_line_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('line_id');
            $table->date('worked_on');
            $table->unsignedSmallInteger('operators');
            $table->unsignedSmallInteger('helpers')->default(0);
            $table->unsignedSmallInteger('minutes');
            $table->string('note', 300)->nullable();
            $table->ulid('updated_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'line_id', 'worked_on'], 'fac_line_days_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_line_days');
        Schema::dropIfExists('fac_operations');
    }
};
