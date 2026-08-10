<?php

use Illuminate\Support\Facades\Route;
use TanemRahman\ZktecoAdms\Http\Controllers\AdmsController;
use TanemRahman\ZktecoAdms\Http\Controllers\CommandController;

$prefix = config('zkteco-adms.route_prefix', 'iclock');

Route::prefix($prefix)->middleware(['zkteco.adms.device'])->name('zkteco.adms.')->group(function () {
    Route::get('cdata', [AdmsController::class, 'handshake'])->name('cdata.init');
    Route::post('cdata', [AdmsController::class, 'receiveData'])->name('cdata.receive');
    Route::get('getrequest', [CommandController::class, 'poll'])->name('getrequest');
    Route::post('devicecmd', [CommandController::class, 'reply'])->name('devicecmd');
    Route::get('ping', [AdmsController::class, 'ping'])->name('ping');
    Route::post('fdata', [AdmsController::class, 'fdata'])->name('fdata');
    Route::match(['get', 'post'], 'registry', [AdmsController::class, 'registry'])->name('registry');
    Route::match(['get', 'post'], 'push', [AdmsController::class, 'push'])->name('push');
});
