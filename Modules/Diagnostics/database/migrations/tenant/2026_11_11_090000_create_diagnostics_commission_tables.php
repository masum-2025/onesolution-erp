<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Referring doctors' commission (H-2b): what each order earns them once its
 * bill is final, corrections as reversing lines, and what was paid out (all
 * append-only, money in minor units); and time-limited report links (only a
 * hash of the token is kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostics_commissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('referrer_id');
            $table->ulid('order_id');
            $table->string('kind', 10);
            $table->ulid('reverses_id')->nullable();
            $table->bigInteger('base_minor');
            $table->unsignedInteger('percent');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('earned_on');
            $table->string('reason', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at');

            $table->unique(['organization_id', 'order_id', 'kind'], 'diagnostics_commissions_order_unique');
            $table->index(['organization_id', 'referrer_id', 'earned_on'], 'diagnostics_commissions_referrer_index');
        });

        Schema::create('diagnostics_payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('referrer_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 10);
            $table->date('paid_on');
            $table->string('note', 300)->nullable();
            $table->ulid('paid_by');
            $table->string('op_id', 64)->nullable();
            $table->timestamp('created_at');

            $table->unique(['organization_id', 'op_id'], 'diagnostics_payouts_op_unique');
            $table->index(['organization_id', 'referrer_id', 'paid_on'], 'diagnostics_payouts_referrer_index');
        });

        Schema::create('diagnostics_report_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('order_id');
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->ulid('created_by')->nullable();
            $table->boolean('sent_by_sms')->default(false);
            $table->unsignedInteger('opened_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'token_hash'], 'diagnostics_report_links_token_unique');
            $table->index(['organization_id', 'order_id'], 'diagnostics_report_links_order_index');
        });
    }

    public function down(): void
    {
        foreach (['diagnostics_report_links', 'diagnostics_payouts', 'diagnostics_commissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
