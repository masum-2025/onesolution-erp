<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SC-2: the token Meta repeats when it checks a partner app's webhook
 * (GET hub.verify_token). Not a secret of value (the signature with the app
 * secret protects every event), but random per app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_apps', function (Blueprint $table) {
            $table->string('webhook_verify_token', 64)->nullable()->after('app_name');
        });
        foreach (DB::table('meta_apps')->whereNull('webhook_verify_token')->pluck('id') as $id) {
            DB::table('meta_apps')->where('id', $id)->update(['webhook_verify_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        Schema::table('meta_apps', function (Blueprint $table) {
            $table->dropColumn('webhook_verify_token');
        });
    }
};
