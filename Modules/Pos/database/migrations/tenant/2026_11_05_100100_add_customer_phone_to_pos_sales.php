<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-4: the customer's mobile number on a sale (E.164), so a cashier finds
 * a returning customer by number with or without CRM, and the CRM contact
 * when CRM is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
            $table->ulid('customer_id')->nullable()->after('customer_phone');
            $table->index(['organization_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'customer_phone']);
            $table->dropColumn(['customer_phone', 'customer_id']);
        });
    }
};
