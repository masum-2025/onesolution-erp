<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| PAY-5: arrears of a salary increment round that starts in a month already
| paid. Each row is one employee's difference for one paid month, worked
| out from that month's payslip facts; planned when the round is applied,
| taken into the next payroll run (run_id) and paid when it is approved.
| Lines and rounds keep the total, so approvers see the cost first.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_increment_arrears', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->ulid('line_id');
            $table->ulid('employee_id');
            $table->ulid('unit_id');
            // The month paid at the old basic ("2027-01") and its slip.
            $table->string('period', 7);
            $table->ulid('slip_id');
            // That month's earnings from the salary structure, at the old and the new basic.
            $table->unsignedBigInteger('previous_minor');
            $table->unsignedBigInteger('new_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('taxable_minor');
            // The basic's part of the difference (provident fund is taken on it).
            $table->unsignedBigInteger('pf_base_minor');
            $table->char('currency_code', 3);
            // planned -> paid (with the run that paid it).
            $table->string('status', 10);
            $table->ulid('run_id')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'employee_id', 'period']);
            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'run_id']);
        });

        Schema::table('pay_increment_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('arrears_minor')->default(0)->after('new_minor');
        });
        Schema::table('pay_increment_rounds', function (Blueprint $table) {
            $table->unsignedBigInteger('arrears_minor')->default(0)->after('new_minor');
        });
    }

    public function down(): void
    {
        Schema::table('pay_increment_rounds', function (Blueprint $table) {
            $table->dropColumn('arrears_minor');
        });
        Schema::table('pay_increment_lines', function (Blueprint $table) {
            $table->dropColumn('arrears_minor');
        });
        Schema::dropIfExists('pay_increment_arrears');
    }
};
