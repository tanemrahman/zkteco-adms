<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_heartbeat_logs')) {
            return;
        }

        Schema::create('zkteco_heartbeat_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('serial')->index();
            $table->string('ip')->nullable();
            $table->text('info')->nullable();
            $table->unsignedInteger('commands_sent')->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_heartbeat_logs');
    }
};
