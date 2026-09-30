<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->boolean('is_house')->default(false);
            $table->ulid('parent_partner_id')->nullable();
            $table->string('billing_mode', 30)->default('direct');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // A key to the same table is added once its primary key exists (PostgreSQL).
        Schema::table('partners', function (Blueprint $table) {
            $table->foreign('parent_partner_id')->references('id')->on('partners')->restrictOnDelete();
        });

        Schema::create('partner_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained('partners')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_users');
        Schema::dropIfExists('partners');
    }
};
