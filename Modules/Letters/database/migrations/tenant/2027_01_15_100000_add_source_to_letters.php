<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| LET-3: what a letter was issued from (another module's record, through
| Letters' LetterSource contract), e.g. a salary increment round; empty for
| letters issued on their own.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('let_letters', function (Blueprint $table) {
            $table->string('source_type', 60)->nullable()->after('op_id');
            $table->ulid('source_id')->nullable()->after('source_type');
            $table->index(['organization_id', 'source_type', 'source_id'], 'let_letters_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('let_letters', function (Blueprint $table) {
            $table->dropIndex('let_letters_source_index');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
