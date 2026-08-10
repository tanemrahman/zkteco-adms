<?php

namespace TanemRahman\ZktecoAdms;

use Illuminate\Support\Facades\Facade;
use TanemRahman\ZktecoAdms\Services\ZktecoAdmsManager;

/**
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDevice|null device(string $serial)
 * @method static \TanemRahman\ZktecoAdms\Services\CommandService commands()
 * @method static \TanemRahman\ZktecoAdms\Services\UserSyncService users()
 * @method static \TanemRahman\ZktecoAdms\Services\AdmsService adms()
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand addUser(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $user)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand deleteUser(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand addFingerprint(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $fp)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand addFace(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $face)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand reboot(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand syncTime(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, ?\DateTimeInterface $time = null)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand info(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand clearLog(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand queryUsers(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin = '')
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand queryAttlog(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, ?string $start = null, ?string $end = null)
 *
 * @see ZktecoAdmsManager
 */
class ZktecoAdms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ZktecoAdmsManager::class;
    }
}
