<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LV-3: unused leave paid out. HR asks (half days of an encashable kind, at
 * most what is left); someone else approves; the days leave the balance and
 * Payroll pays them in the run whose days include pay_on (the day it was
 * approved). Never money here: Payroll works the amount out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_encashments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->ulid('type_id');
            $table->unsignedSmallInteger('leave_year');
            $table->unsignedSmallInteger('halves');
            $table->text('reason');
            $table->string('status', 20);
            $table->ulid('requested_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->date('pay_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'status', 'pay_on']);
            $table->index(['employee_id', 'leave_year']);
            $table->index(['unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_encashments');
    }
};
