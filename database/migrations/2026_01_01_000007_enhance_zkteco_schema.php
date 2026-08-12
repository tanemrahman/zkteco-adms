<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-create schema enhancements (idempotent):
 * - unique serial on zkteco_devices
 * - transactions unique includes status
 * - workcode + zkteco_attphotos
 * - verify_mode + is_blocked on device users
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->uniqueDeviceSerial();
        $this->transactionsUniqueWithStatus();
        $this->workcodeAndAttphotos();
        $this->verifyModeAndBlock();
    }

    public function down(): void
    {
        if (Schema::hasTable('zkteco_device_users')) {
            Schema::table('zkteco_device_users', function (Blueprint $table) {
                if (Schema::hasColumn('zkteco_device_users', 'is_blocked')) {
                    $table->dropColumn('is_blocked');
                }
                if (Schema::hasColumn('zkteco_device_users', 'verify_mode')) {
                    $table->dropColumn('verify_mode');
                }
            });
        }

        Schema::dropIfExists('zkteco_attphotos');

        if (Schema::hasTable('zkteco_transactions') && Schema::hasColumn('zkteco_transactions', 'workcode')) {
            Schema::table('zkteco_transactions', function (Blueprint $table) {
                $table->dropColumn('workcode');
            });
        }

        if (Schema::hasTable('zkteco_transactions')) {
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

        if (Schema::hasTable('zkteco_devices')) {
            Schema::table('zkteco_devices', function (Blueprint $table) {
                try {
                    $table->dropUnique('zkteco_devices_serial_unique');
                } catch (\Throwable) {
                    // ignore
                }
            });
        }
    }

    protected function uniqueDeviceSerial(): void
    {
        if (! Schema::hasTable('zkteco_devices')) {
            return;
        }

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

    protected function transactionsUniqueWithStatus(): void
    {
        if (! Schema::hasTable('zkteco_transactions')) {
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

    protected function workcodeAndAttphotos(): void
    {
        if (Schema::hasTable('zkteco_transactions') && ! Schema::hasColumn('zkteco_transactions', 'workcode')) {
            Schema::table('zkteco_transactions', function (Blueprint $table) {
                $table->string('workcode', 64)->nullable()->after('verify');
            });
        }

        if (! Schema::hasTable('zkteco_attphotos')) {
            Schema::create('zkteco_attphotos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id')->nullable()->index();
                $table->string('serial')->index();
                $table->string('pin')->nullable()->index();
                $table->string('pin_raw')->nullable();
                $table->dateTime('captured_at')->nullable()->index();
                $table->string('disk')->nullable();
                $table->string('path');
                $table->unsignedInteger('size')->default(0);
                $table->string('cmd')->nullable();
                $table->string('stamp')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function verifyModeAndBlock(): void
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
};
