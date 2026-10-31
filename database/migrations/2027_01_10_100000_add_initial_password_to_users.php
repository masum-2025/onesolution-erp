<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logins given with a first password someone else chose (an employer for its
 * employees): the person must choose their own before working anywhere, and
 * the first password stops working after initial_password_expires_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('initial_password_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'initial_password_expires_at']);
        });
    }
};
