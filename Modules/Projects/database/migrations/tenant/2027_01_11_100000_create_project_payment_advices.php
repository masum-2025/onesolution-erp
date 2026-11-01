<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payments a connected client says it made against a project's invoice
 * (compliance.payment_advice), in the provider's own database. Nothing is
 * posted from here by itself: someone checks the money arrived and records
 * the receipt in Accounting (settlement_id), or answers that it did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_payment_advices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The project's unit.
            $table->ulid('organization_id')->index();
            $table->foreignUlid('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->ulid('connection_id');
            // The client's own advice id and the unit it came from (the answer goes back there).
            $table->ulid('client_advice_id');
            $table->ulid('client_unit_id');
            $table->ulid('message_id');
            $table->string('from_name', 190);
            $table->string('by_name', 190)->nullable();
            // The Accounting invoice it pays.
            $table->ulid('invoice_id');
            $table->string('invoice_number', 60)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->date('paid_on');
            $table->string('method', 10);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            // new | recorded | not_received
            $table->string('status', 14);
            // The receipt made in Accounting (it may still wait for approval there).
            $table->ulid('settlement_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['connection_id', 'client_advice_id']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_payment_advices');
    }
};
