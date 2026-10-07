<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-1: a company's customers and sales work (in the client's database).
 *
 * organization_id is the company on every table; unit_id is the branch or
 * department a record belongs to (who may see it). Money is integer minor
 * units with its currency; quantities integer thousandths; rates basis
 * points. extra holds the company's own fields (crm_fields), checked on
 * the way in.
 *
 * - crm_contacts: people and organizations (phone in E.164, unique per company).
 * - crm_pipelines + crm_stages: each company's stages for deals.
 * - crm_deals: an opportunity with a contact, moving through the stages.
 * - crm_activities: calls, meetings, notes and follow-ups (tasks with a time).
 * - crm_quotes + crm_quote_lines: estimates and quotations.
 * - crm_fields: extra fields the company adds to contacts, deals, quotes and lines.
 * - crm_points: loyalty points earned (append-only).
 * - crm_sequences: quote numbers per kind and year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            // person | organization
            $table->string('kind', 12)->default('person');
            $table->string('name', 150);
            $table->string('company_name', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->json('address')->nullable();
            $table->json('tags')->nullable();
            $table->string('source', 40)->nullable();
            $table->ulid('owner_id')->nullable();
            $table->boolean('sms_consent')->default(false);
            $table->boolean('email_consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->json('extra')->nullable();
            // Kept up to date from sales (POS) and quotes.
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->date('last_purchase_on')->nullable();
            $table->bigInteger('points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('anonymized_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'phone']);
            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'unit_id']);
            $table->index(['organization_id', 'name']);
        });

        Schema::create('crm_pipelines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('key', 40);
            $table->json('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        Schema::create('crm_stages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->string('key', 40);
            $table->json('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Chance of winning, in basis points (10000 = sure).
            $table->unsignedSmallInteger('probability_bp')->default(0);
            // open | won | lost
            $table->string('outcome', 8)->default('open');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['pipeline_id', 'key']);
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->foreignUlid('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignUlid('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->foreignUlid('stage_id')->constrained('crm_stages')->restrictOnDelete();
            $table->string('title', 150);
            $table->unsignedBigInteger('value_minor')->default(0);
            $table->char('currency_code', 3);
            $table->date('expected_on')->nullable();
            $table->ulid('owner_id')->nullable();
            // open | won | lost
            $table->string('status', 8)->default('open');
            $table->string('lost_reason', 300)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('extra')->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'status', 'stage_id']);
            $table->index(['organization_id', 'contact_id']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->foreignUlid('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->ulid('deal_id')->nullable();
            // call | meeting | note | task | sms | email | visit
            $table->string('kind', 12);
            $table->string('subject', 150);
            $table->text('body')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->ulid('assigned_to')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'contact_id']);
            $table->index(['organization_id', 'assigned_to', 'done_at', 'due_at']);
        });

        Schema::create('crm_quotes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            // estimate | quotation
            $table->string('kind', 10);
            $table->string('number', 40)->nullable();
            // draft | sent | accepted | declined | expired | converted
            $table->string('status', 10)->default('draft');
            $table->foreignUlid('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->ulid('deal_id')->nullable();
            $table->ulid('from_quote_id')->nullable();
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->string('subject', 150)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->json('extra')->nullable();
            $table->ulid('invoice_id')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'number']);
            $table->index(['organization_id', 'contact_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_quote_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('quote_id')->constrained('crm_quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            // An Inventory item, or a free line (service, labour).
            $table->ulid('item_id')->nullable();
            $table->string('description', 255);
            $table->unsignedBigInteger('quantity_milli');
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->ulid('tax_code_id')->nullable();
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->json('extra')->nullable();

            $table->index(['quote_id', 'line_no']);
        });

        Schema::create('crm_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // contact | deal | quote | quote_line
            $table->string('entity', 12);
            $table->string('key', 40);
            $table->json('label');
            // text | long_text | number | money | date | choice | yes_no
            $table->string('type', 12);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            // Shown on the printed quote / estimate.
            $table->boolean('on_print')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'entity', 'key']);
        });

        Schema::create('crm_points', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->bigInteger('points');
            // earned | returned | adjusted
            $table->string('reason', 12);
            $table->string('source_module', 30);
            $table->string('source_type', 30);
            $table->ulid('source_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['source_module', 'source_type', 'source_id']);
            $table->index(['organization_id', 'contact_id']);
        });

        Schema::create('crm_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'year']);
        });
    }

    public function down(): void
    {
        foreach (['crm_sequences', 'crm_points', 'crm_fields', 'crm_quote_lines', 'crm_quotes', 'crm_activities', 'crm_deals', 'crm_stages', 'crm_pipelines', 'crm_contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
