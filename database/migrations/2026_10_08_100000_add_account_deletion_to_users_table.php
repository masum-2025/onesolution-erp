<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Delete my account" (Phase 5C-3): the person asks, a grace period runs
 * (they can still sign in and cancel), then their personal data is erased.
 * The row itself stays, anonymized, so audit trails and records owned by
 * B2B clients keep pointing at "a deleted person".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('onboarded_at');
            $table->timestamp('deletion_due_at')->nullable()->index()->after('deletion_requested_at');
            $table->timestamp('erased_at')->nullable()->after('deletion_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_due_at']);
            $table->dropColumn(['deletion_requested_at', 'deletion_due_at', 'erased_at']);
        });
    }
};
