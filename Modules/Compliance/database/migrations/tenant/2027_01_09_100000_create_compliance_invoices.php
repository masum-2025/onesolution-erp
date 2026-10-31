<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Invoices a connected provider sent (projects.invoice), in the client's
 * own database: the provider's copy as it last came (status and what is
 * still owed follow the provider's books), one row per invoice. Taken into
 * the client's own books once, as a draft bill (bill_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmp_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The factory it was sent to.
            $table->ulid('organization_id')->index();
            $table->ulid('connection_id');
            // The provider's own invoice id; one row per invoice and connection.
            $table->ulid('provider_invoice_id');
            // The last message applied (the same message again changes nothing).
            $table->ulid('message_id');
            // When the provider sent it (milliseconds): an older copy arriving late changes nothing.
            $table->unsignedBigInteger('as_of')->default(0);
            $table->foreignUlid('request_id')->nullable()->constrained('cmp_requests')->restrictOnDelete();
            $table->string('from_name', 190);
            $table->string('number', 60)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('balance_minor')->default(0);
            // [{description, quantity_milli, unit_price_minor, net_minor, tax_rate_bp, tax_minor, total_minor}]
            $table->json('lines');
            $table->text('notes')->nullable();
            // posted | partly_paid | paid | void (as the provider's books have it)
            $table->string('provider_status', 20);
            // The draft bill made in the client's books, once.
            $table->ulid('bill_id')->nullable();
            $table->ulid('billed_by')->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['connection_id', 'provider_invoice_id']);
            $table->index(['organization_id', 'provider_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmp_invoices');
    }
};
