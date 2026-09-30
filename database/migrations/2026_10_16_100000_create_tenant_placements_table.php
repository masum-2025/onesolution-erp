<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: which database holds a client tree's business data. No row means
 * the main (shared) database. Only a database NAME is stored; connection
 * details stay in the environment (config/tenant_databases.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_placements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('root_organization_id')->unique()->constrained('organizations');
            $table->string('strategy', 20); // shared | dedicated | regional
            $table->string('database', 40)->nullable(); // null = the main database
            $table->string('status', 20)->default('active'); // active | moving
            $table->timestamp('status_changed_at')->nullable();
            // Where the data was before its last move (kept until purged).
            $table->string('previous_database', 40)->nullable();
            $table->timestamp('previous_retained_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_placements');
    }
};
