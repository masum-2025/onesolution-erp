<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A vendor made for a business connection's provider (its invoices come in
 * over the connection): found again by the connection, one per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_parties', function (Blueprint $table) {
            $table->ulid('connection_id')->nullable();
            $table->index(['organization_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::table('acc_parties', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'connection_id']);
            $table->dropColumn('connection_id');
        });
    }
};
