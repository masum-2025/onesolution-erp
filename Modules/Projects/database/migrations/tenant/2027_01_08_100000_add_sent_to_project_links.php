<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A quote sent to a connected client over the business connection: when,
 * and by whom (the client's answer is applied in CRM in that person's name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_project_links', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
            $table->ulid('sent_by')->nullable();
            // The client's answer as it came (accepted | declined), and its reason.
            $table->string('client_answer', 10)->nullable();
            $table->string('client_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prj_project_links', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_by', 'client_answer', 'client_reason']);
        });
    }
};
