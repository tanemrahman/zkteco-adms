<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_device_commands')) {
            return;
        }

        Schema::create('zkteco_device_commands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('serial')->index();
            $table->string('type')->default('CUSTOM');
            $table->text('command');
            $table->string('status')->default('pending')->index();
            $table->integer('return_code')->nullable();
            $table->string('return_value')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['serial', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_device_commands');
    }
};
