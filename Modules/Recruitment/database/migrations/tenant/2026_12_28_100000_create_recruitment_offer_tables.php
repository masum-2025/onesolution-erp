<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Offer letters (REC-3). Salaries are minor units with the currency.
 *
 * - rec_letter_templates: the company's own offer letter, per language,
 *   with {placeholders} (one per company; the rule's wording until then).
 * - rec_offers: an offer to an application: the salary, the first day,
 *   the day to answer by; approved by someone else when the rule asks;
 *   the letter kept as sent (in the candidate's language) so it reads the
 *   same later; answered by the candidate through a link (only its hash
 *   kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_letter_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->json('body');
            $table->unsignedInteger('version');
            $table->timestamps();
        });

        Schema::create('rec_offers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('application_id')->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('salary_minor');
            $table->date('start_on');
            $table->date('respond_by');
            $table->string('status', 20);
            $table->text('letter')->nullable();
            $table->string('locale', 10)->nullable();
            $table->char('token_hash', 64)->nullable()->index();
            $table->ulid('created_by');
            $table->ulid('approved_by')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_offers');
        Schema::dropIfExists('rec_letter_templates');
    }
};
