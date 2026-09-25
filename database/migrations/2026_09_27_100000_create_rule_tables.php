<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirror of the rule catalog (module manifests), refreshed by `php artisan rules:sync`.
        Schema::create('rule_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 120)->unique();
            $table->string('module_key', 50)->index();
            $table->string('type', 20);
            $table->json('schema')->nullable();
            $table->json('default_value')->nullable();
            $table->boolean('nullable')->default(false);
            // Translation keys (module lang files hold en / bn text).
            $table->string('label', 200);
            $table->string('description', 200);
            $table->json('overridable_levels');
            $table->string('edit_permission', 120);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('sensitive')->default(false);
            $table->boolean('country_specific')->default(false);
            $table->string('category', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rule_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('rule_key', 120);
            $table->string('scope_type', 20);
            // Partner / organization / role / user ULID, plan key, or null for platform.
            $table->string('scope_id', 50)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('mode', 20);
            $table->json('value')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reason');
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'rule_key', 'status']);
            $table->index(['rule_key', 'status']);
        });

        // Immutable trail of every state change of a rule value.
        Schema::create('rule_value_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rule_value_id')->constrained('rule_values')->restrictOnDelete();
            $table->string('rule_key', 120)->index();
            $table->string('scope_type', 20);
            $table->string('scope_id', 50)->nullable();
            $table->string('action', 30);
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->json('snapshot');
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scope_type', 'scope_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_value_history');
        Schema::dropIfExists('rule_values');
        Schema::dropIfExists('rule_definitions');
    }
};
