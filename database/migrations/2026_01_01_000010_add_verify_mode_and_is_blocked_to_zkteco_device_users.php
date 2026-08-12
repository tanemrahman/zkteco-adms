<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional USERINFO Verify= mode + soft punch-block flag on the local roster.
 * Apps can use these without custom schema migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zkteco_device_users')) {
            return;
        }

        Schema::table('zkteco_device_users', function (Blueprint $table) {
            if (! Schema::hasColumn('zkteco_device_users', 'verify_mode')) {
                $table->integer('verify_mode')->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('zkteco_device_users', 'is_blocked')) {
                $table->boolean('is_blocked')->default(false)->after('verify_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('zkteco_device_users')) {
            return;
        }

        Schema::table('zkteco_device_users', function (Blueprint $table) {
            if (Schema::hasColumn('zkteco_device_users', 'is_blocked')) {
                $table->dropColumn('is_blocked');
            }
            if (Schema::hasColumn('zkteco_device_users', 'verify_mode')) {
                $table->dropColumn('verify_mode');
            }
        });
    }
};
