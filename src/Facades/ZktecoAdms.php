<?php

namespace TanemRahman\ZktecoAdms\Facades;

use Illuminate\Support\Facades\Facade;
use TanemRahman\ZktecoAdms\Services\ZktecoAdmsManager;

/**
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDevice|null device(string $serial)
 * @method static \TanemRahman\ZktecoAdms\Services\CommandService commands()
 * @method static \TanemRahman\ZktecoAdms\Services\UserSyncService users()
 * @method static \TanemRahman\ZktecoAdms\Services\AdmsService adms()
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand addUser(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $user)
 * @method static array addUsers(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $users)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand deleteUser(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand blockUser(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand unblockUser(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand addFingerprint(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $fp)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand deleteFingerprint(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin, int $fid = 0)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand addFace(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, array $face)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand deleteFace(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin, int $fid = 0)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand reboot(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand syncTime(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, ?\DateTimeInterface $time = null)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand info(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand check(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand clearLog(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand clearData(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand queryUsers(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, string|int $pin = '')
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand queryAttlog(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device, ?string $start = null, ?string $end = null)
 * @method static \TanemRahman\ZktecoAdms\Models\ZktecoDevice resetStamps(\TanemRahman\ZktecoAdms\Models\ZktecoDevice|string $device)
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
