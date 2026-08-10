<?php

namespace TanemRahman\ZktecoAdms\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use TanemRahman\ZktecoAdms\Console\Commands\AdmsCommandsCommand;
use TanemRahman\ZktecoAdms\Console\Commands\AdmsDevicesCommand;
use TanemRahman\ZktecoAdms\Console\Commands\AdmsMaintenanceCommand;
use TanemRahman\ZktecoAdms\Http\Middleware\AdmsDevice;
use TanemRahman\ZktecoAdms\Services\AdmsService;
use TanemRahman\ZktecoAdms\Services\CommandService;
use TanemRahman\ZktecoAdms\Services\DeviceIdentityService;
use TanemRahman\ZktecoAdms\Services\UserSyncService;
use TanemRahman\ZktecoAdms\Services\ZktecoAdmsManager;

class ZktecoAdmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/zkteco-adms.php', 'zkteco-adms');

        $this->app->singleton(AdmsService::class);
        $this->app->singleton(CommandService::class);
        $this->app->singleton(DeviceIdentityService::class);
        $this->app->singleton(UserSyncService::class);
        $this->app->singleton(ZktecoAdmsManager::class);

        $this->commands([
            AdmsMaintenanceCommand::class,
            AdmsDevicesCommand::class,
            AdmsCommandsCommand::class,
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
            Schedule::command('zkteco-adms:commands --requeue-stale')->everyThirtyMinutes();
            Schedule::command('zkteco-adms:commands --prune')->dailyAt('02:00');
        });
    }
}
