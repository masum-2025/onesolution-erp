<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ZKT-3: office bridges, for an app hosted online.
 *
 * - zk_bridges: a small program on an office computer that reads the
 *   devices on its network and sends what it read to the app. Known by a
 *   token shown once (only its SHA-256 is kept, with its last characters
 *   to tell tokens apart); when it last called and from where; revoked.
 * - zk_devices.bridge_id: the bridge that reads the device (null: the app
 *   reads it itself, on the same network).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zk_bridges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('name', 80);
            $table->char('token_hash', 64);
            $table->string('token_hint', 8);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->ulid('created_by');
            $table->timestamps();

            $table->unique('token_hash', 'zk_bridges_token_unique');
            $table->index(['organization_id', 'unit_id'], 'zk_bridges_unit_index');
        });

        Schema::table('zk_devices', function (Blueprint $table) {
            $table->ulid('bridge_id')->nullable()->after('unit_id');
            $table->index(['organization_id', 'bridge_id'], 'zk_devices_bridge_index');
        });
    }

    public function down(): void
    {
        Schema::table('zk_devices', function (Blueprint $table) {
            $table->dropIndex('zk_devices_bridge_index');
            $table->dropColumn('bridge_id');
        });
        Schema::dropIfExists('zk_bridges');
    }
};
