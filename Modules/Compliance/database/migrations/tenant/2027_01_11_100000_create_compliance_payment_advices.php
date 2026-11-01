<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payments the client told a connected provider about
 * (compliance.payment_advice), in the client's own database: one row per
 * payment, against one received invoice. A money record: written once, only
 * the provider's answer (recorded or not received) is added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmp_payment_advices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The factory the invoice came to.
            $table->ulid('organization_id')->index();
            $table->foreignUlid('invoice_id')->constrained('cmp_invoices')->restrictOnDelete();
            $table->ulid('connection_id');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->date('paid_on');
            // bank | cash | cheque | mobile | other
            $table->string('method', 10);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            // sent | recorded | not_received (the provider's answer)
            $table->string('status', 14);
            $table->string('reply_reason', 500)->nullable();
            $table->timestamp('replied_at')->nullable();
            // One tap, one advice (offline-safe).
            $table->string('op_id', 64);
            $table->ulid('sent_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'op_id']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmp_payment_advices');
    }
};
