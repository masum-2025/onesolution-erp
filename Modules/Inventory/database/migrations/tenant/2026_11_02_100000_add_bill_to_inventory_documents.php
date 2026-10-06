<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INV-3: the supplier bill made from a goods receipt (Accounting's draft
 * bill id), so a receipt is billed once and shows its bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_documents', function (Blueprint $table) {
            $table->ulid('bill_id')->nullable()->after('journal_id');
            $table->timestamp('billed_at')->nullable()->after('bill_id');
            $table->unique('bill_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_documents', function (Blueprint $table) {
            $table->dropUnique(['bill_id']);
            $table->dropColumn(['bill_id', 'billed_at']);
        });
    }
};
