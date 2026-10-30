<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Renewal requests from connected clients (optional, over a business
 * connection): what a client's Compliance sent (compliance.renewal_request)
 * arrives here; accepting it opens a project that remembers the connection,
 * the client's factory and the request, so the answer and the result go back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The provider company that received it.
            $table->ulid('organization_id')->index();
            $table->ulid('connection_id')->index();
            // The client's own request id (sent back with every answer).
            $table->ulid('client_request_id');
            // Delivered once: the same message again finds this row.
            $table->ulid('message_id')->unique();
            $table->ulid('client_unit_id');
            $table->string('client_name', 190);
            $table->string('factory_name', 190);
            $table->string('service_code', 30)->nullable();
            $table->json('service_names')->nullable();
            $table->date('expires_on')->nullable();
            $table->date('wanted_by')->nullable();
            $table->text('note')->nullable();
            $table->string('requested_by', 190)->nullable();
            // new | accepted | declined | cancelled
            $table->string('status', 10);
            $table->string('reason', 500)->nullable();
            $table->ulid('project_id')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['connection_id', 'client_request_id']);
        });

        Schema::table('prj_projects', function (Blueprint $table) {
            $table->ulid('connection_id')->nullable();
            $table->ulid('client_unit_id')->nullable();
            $table->ulid('request_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prj_projects', function (Blueprint $table) {
            $table->dropColumn(['connection_id', 'client_unit_id', 'request_id']);
        });
        Schema::dropIfExists('prj_requests');
    }
};
