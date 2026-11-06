<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MKT-3b: people who wrote through a company website's contact form, for
 * the partner's owners and sales staff to follow up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mkt_leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('mkt_sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('company', 150)->nullable();
            $table->text('message')->nullable();
            // Where it was sent from: the page and the language the visitor read.
            $table->string('page_slug', 80);
            $table->string('locale', 10);
            $table->string('status', 20)->default('new');
            $table->text('note')->nullable();
            $table->ulid('handled_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['site_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_leads');
    }
};
