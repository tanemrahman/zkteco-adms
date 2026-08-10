<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_devices')) {
            return;
        }

        Schema::create('zkteco_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alias')->nullable();
            $table->string('ip')->nullable();
            $table->integer('port')->default(443);
            $table->string('model')->nullable();
            $table->string('serial')->nullable()->index();
            $table->string('firmware')->nullable();
            $table->string('push_version')->nullable();
            $table->string('platform')->nullable();
            $table->string('device_name')->nullable();
            $table->string('password')->nullable();
            $table->string('comm_key')->nullable();
            $table->boolean('status')->default(true);
            $table->string('protocol')->default('adms'); // adms | biotime
            $table->boolean('is_registered')->default(false);
            $table->integer('timezone')->nullable();
            $table->integer('user_count')->default(0);
            $table->integer('fp_count')->default(0);
            $table->integer('face_count')->default(0);
            $table->integer('transaction_count')->default(0);
            $table->string('last_attlog_stamp')->nullable();
            $table->string('last_operlog_stamp')->nullable();
            $table->string('last_attphoto_stamp')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_devices');
    }
};
