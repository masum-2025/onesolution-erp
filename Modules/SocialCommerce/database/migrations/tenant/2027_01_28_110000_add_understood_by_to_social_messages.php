<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SC-4: how a customer's message was understood: by its keywords or by the AI assistant. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_messages', function (Blueprint $table) {
            $table->string('understood_by', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('social_messages', function (Blueprint $table) {
            $table->dropColumn('understood_by');
        });
    }
};
