<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zkteco_adms_logs')) {
            return;
        }

        Schema::create('zkteco_adms_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('serial')->nullable()->index();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->string('table_name')->nullable();
            $table->string('level')->default('info')->index();
            $table->text('query')->nullable();
            $table->longText('body')->nullable();
            $table->longText('response')->nullable();
            $table->integer('status_code')->nullable();
            $table->integer('records_count')->nullable();
            $table->string('message')->nullable();
            $table->string('ip')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_adms_logs');
    }
};
