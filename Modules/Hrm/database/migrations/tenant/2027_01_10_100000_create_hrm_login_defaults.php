<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logins for employees with a first password the company chose.
 *
 * - hrm_login_defaults: one per company; only the hash of the first password
 *   (nobody can read it back), and the roles new logins get.
 * - hrm_imports.create_logins: the import gives every hired row a login.
 * - hrm_import_rows.login: what happened to that row's login
 *   (created | linked | failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_login_defaults', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->string('password_hash')->nullable();
            $table->ulid('password_set_by')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->json('role_ids')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::table('hrm_imports', function (Blueprint $table) {
            $table->boolean('create_logins')->default(false)->after('status');
        });

        Schema::table('hrm_import_rows', function (Blueprint $table) {
            $table->string('login', 10)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('hrm_import_rows', function (Blueprint $table) {
            $table->dropColumn('login');
        });
        Schema::table('hrm_imports', function (Blueprint $table) {
            $table->dropColumn('create_logins');
        });
        Schema::dropIfExists('hrm_login_defaults');
    }
};
