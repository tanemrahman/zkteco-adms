<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_attphotos')) {
            return;
        }

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

    public function down(): void
    {
        Schema::dropIfExists('zkteco_attphotos');
    }
};
