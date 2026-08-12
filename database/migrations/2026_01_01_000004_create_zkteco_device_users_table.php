<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_device_users')) {
            return;
        }

        Schema::create('zkteco_device_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('serial')->index();
            $table->string('pin');
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->string('password')->nullable();
            $table->string('card')->nullable();
            $table->string('group')->nullable();
            $table->string('timezone')->nullable();
            $table->integer('verify_mode')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->boolean('has_fp')->default(false);
            $table->boolean('has_face')->default(false);
            $table->integer('fp_count')->default(0);
            $table->integer('face_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['serial', 'pin'], 'zkteco_device_users_serial_pin_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_device_users');
    }
};
