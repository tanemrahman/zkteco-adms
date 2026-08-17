<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zkteco_device_users', function (Blueprint $table) {
            if (! Schema::hasColumn('zkteco_device_users', 'fp_fids')) {
                $table->json('fp_fids')->nullable()->after('fp_count');
            }
            if (! Schema::hasColumn('zkteco_device_users', 'face_fids')) {
                $table->json('face_fids')->nullable()->after('face_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zkteco_device_users', function (Blueprint $table) {
            $table->dropColumn(['fp_fids', 'face_fids']);
        });
    }
};
