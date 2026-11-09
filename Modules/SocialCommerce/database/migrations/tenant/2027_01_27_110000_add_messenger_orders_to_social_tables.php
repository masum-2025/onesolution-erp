<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-3: orders taken in Messenger.
 *
 * - social_pages: the branch and warehouse a page's orders go to (ids of
 *   the company's units and Inventory's warehouses; empty: no orders).
 * - social_threads: the order being taken (step and answers so far, json;
 *   gone once placed, cancelled or too old), when it started, and the last
 *   order placed from the conversation (Online orders' id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_pages', function (Blueprint $table) {
            $table->ulid('order_branch_id')->nullable()->after('price_public');
            $table->ulid('order_warehouse_id')->nullable()->after('order_branch_id');
        });
        Schema::table('social_threads', function (Blueprint $table) {
            $table->json('order_draft')->nullable()->after('unknown_count');
            $table->timestamp('draft_at')->nullable()->after('order_draft');
            $table->ulid('last_order_id')->nullable()->after('draft_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_threads', function (Blueprint $table) {
            $table->dropColumn(['order_draft', 'draft_at', 'last_order_id']);
        });
        Schema::table('social_pages', function (Blueprint $table) {
            $table->dropColumn(['order_branch_id', 'order_warehouse_id']);
        });
    }
};
