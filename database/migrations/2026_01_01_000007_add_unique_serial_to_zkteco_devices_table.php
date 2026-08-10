<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('zkteco_devices')) {
            return;
        }

        // Drop blank duplicate serials before unique index (keep lowest id).
        $duplicates = DB::table('zkteco_devices')
            ->select('serial', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
            ->whereNotNull('serial')
            ->where('serial', '!=', '')
            ->groupBy('serial')
            ->having('c', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('zkteco_devices')
                ->where('serial', $dup->serial)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        Schema::table('zkteco_devices', function (Blueprint $table) {
            try {
                $table->unique('serial', 'zkteco_devices_serial_unique');
            } catch (\Throwable) {
                // Index may already exist.
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('zkteco_devices')) {
            return;
        }

        Schema::table('zkteco_devices', function (Blueprint $table) {
            try {
                $table->dropUnique('zkteco_devices_serial_unique');
            } catch (\Throwable) {
                // ignore
            }
        });
    }
};
