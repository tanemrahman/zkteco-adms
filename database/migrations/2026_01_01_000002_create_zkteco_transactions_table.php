<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_transactions')) {
            return;
        }

        Schema::create('zkteco_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->default(0)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // device PIN / emp_code
            $table->dateTime('timestamp');
            $table->tinyInteger('status')->default(0); // punch state
            $table->integer('verify')->nullable();
            $table->string('workcode', 64)->nullable();
            $table->string('source')->default('adms')->index(); // adms | biotime
            $table->string('terminal_sn')->nullable()->index();
            $table->timestamps();

                $table->unique(['device_id', 'user_id', 'timestamp', 'status'], 'zkteco_transactions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_transactions');
    }
};
