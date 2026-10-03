<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM-3b: which expiry reminders were sent for which document, so each one
 * goes once. stage = the "days before" number it was sent for (rule
 * hrm.document_expiry_alert_days), or -1 for "has expired".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_document_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('document_id');
            $table->smallInteger('stage');
            $table->date('sent_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'stage']);
            $table->foreign('document_id')->references('id')->on('hrm_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_document_alerts');
    }
};
