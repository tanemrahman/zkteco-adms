<?php

namespace TanemRahman\ZktecoAdms\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use TanemRahman\ZktecoAdms\Console\Commands\AdmsMaintenanceCommand;
use TanemRahman\ZktecoAdms\Http\Middleware\AdmsDevice;

class ZktecoAdmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/zkteco-adms.php', 'zkteco-adms');

        $this->commands([
            AdmsMaintenanceCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../Config/zkteco-adms.php' => config_path('zkteco-adms.php'),
        ], 'zkteco-adms-config');

        $this->publishes([
            __DIR__ . '/../Database/Migrations' => database_path('migrations'),
        ], 'zkteco-adms-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        Route::aliasMiddleware('zkteco.adms.device', AdmsDevice::class);
        $this->loadRoutesFrom(__DIR__ . '/../Routes/adms.php');

        $this->app->booted(function () {
            Schedule::command('zkteco-adms:maintain --requeue-stale')->everyThirtyMinutes();
            Schedule::command('zkteco-adms:maintain --prune')->dailyAt('02:00');
        });
    }
}
