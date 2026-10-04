<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-4a: tax (VAT) on invoices and bills.
 *
 * - acc_tax_codes: a company's own tax codes (copied from its country's tax
 *   profile), rates in basis points (15% = 1500).
 * - Document lines get the tax code used and the tax amount; documents get
 *   their net and tax totals (total = net + tax) and whether the prices
 *   typed included tax. Existing documents had no tax: net = total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_tax_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 20);
            $table->json('name');
            $table->unsignedInteger('rate_bp');
            $table->string('kind', 20);
            $table->string('applies_to', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::table('acc_document_lines', function (Blueprint $table) {
            $table->ulid('tax_code_id')->nullable();
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
        });

        Schema::table('acc_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->boolean('prices_include_tax')->default(false);
        });

        // Documents written before tax: everything was net.
        DB::table('acc_documents')->orderBy('id')->select(['id', 'total_minor'])->chunkById(500, function ($documents) {
            foreach ($documents as $document) {
                DB::table('acc_documents')->where('id', $document->id)->update(['net_minor' => $document->total_minor]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('acc_documents', fn (Blueprint $table) => $table->dropColumn(['net_minor', 'tax_minor', 'prices_include_tax']));
        Schema::table('acc_document_lines', fn (Blueprint $table) => $table->dropColumn(['tax_code_id', 'tax_rate_bp', 'tax_minor']));
        Schema::dropIfExists('acc_tax_codes');
    }
};
