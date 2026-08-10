<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('zkteco_transactions')) {
            return;
        }

        Schema::table('zkteco_transactions', function (Blueprint $table) {
            try {
                $table->dropUnique('zkteco_transactions_unique');
            } catch (\Throwable) {
                // ignore
            }

            try {
                $table->unique(
                    ['device_id', 'user_id', 'timestamp', 'status'],
                    'zkteco_transactions_unique'
                );
            } catch (\Throwable) {
                // ignore if already present
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('zkteco_transactions')) {
            return;
        }

        Schema::table('zkteco_transactions', function (Blueprint $table) {
            try {
                $table->dropUnique('zkteco_transactions_unique');
            } catch (\Throwable) {
                // ignore
            }

            try {
                $table->unique(
                    ['device_id', 'user_id', 'timestamp'],
                    'zkteco_transactions_unique'
                );
            } catch (\Throwable) {
                // ignore
            }
        });
    }
};
