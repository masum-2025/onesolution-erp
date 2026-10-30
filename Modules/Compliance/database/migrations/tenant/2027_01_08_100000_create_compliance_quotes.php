<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quotations a connected provider sent (projects.quotation), in the
 * client's own database: what was offered, line by line, until someone of
 * the client accepts or declines it (the answer goes back to the provider).
 * A newer version of the same quotation replaces a pending one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmp_quotes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The factory it was sent to.
            $table->ulid('organization_id')->index();
            $table->ulid('connection_id')->index();
            // Delivered once: the same message again finds this row.
            $table->ulid('message_id')->unique();
            // The provider's own quotation id (sent back with the answer).
            $table->ulid('provider_quote_id');
            $table->foreignUlid('request_id')->nullable()->constrained('cmp_requests')->restrictOnDelete();
            $table->string('from_name', 190);
            $table->string('number', 60)->nullable();
            $table->string('subject', 190)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            // [{description, quantity_milli, unit, unit_price_minor, discount_minor, tax_rate_bp, net_minor, tax_minor, total_minor}]
            $table->json('lines');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            // pending | accepted | declined | replaced
            $table->string('status', 10);
            $table->string('reason', 500)->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['connection_id', 'provider_quote_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmp_quotes');
    }
};
