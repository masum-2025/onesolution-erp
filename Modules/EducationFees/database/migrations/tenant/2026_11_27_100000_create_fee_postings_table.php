<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEE-2b: fees into the books.
 *
 * - fee_postings: what still has to reach the books (a bill issued, a fine,
 *   a receipt, an advance used, a void, a cancelled bill, fewer credits, a
 *   refund), written in the same transaction as the money row. A queued job
 *   posts each through Accounting's Ledger (op id "fee-<kind>-<id>", so
 *   twice is once); a failure keeps the error and is tried again by
 *   education-fees:post-pending. The counter never waits for the books.
 * - fee_advances.head_id: the fee head an advance came from (fewer credits),
 *   so its income is taken back from the right account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_postings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // bill | fine | receipt | advance | void | cancel | refund
            $table->string('kind', 10);
            $table->ulid('source_id');
            // pending | posted | failed
            $table->string('status', 8)->default('pending');
            $table->ulid('journal_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 300)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'source_id'], 'fee_postings_source_unique');
            $table->index(['organization_id', 'status']);
        });

        Schema::table('fee_advances', function (Blueprint $table) {
            $table->ulid('head_id')->nullable()->after('refund_id');
        });
    }

    public function down(): void
    {
        Schema::table('fee_advances', function (Blueprint $table) {
            $table->dropColumn('head_id');
        });
        Schema::dropIfExists('fee_postings');
    }
};
