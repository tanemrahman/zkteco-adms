<?php

namespace TanemRahman\ZktecoAdms\Services;

use DateTimeInterface;
use TanemRahman\ZktecoAdms\Exceptions\DeviceNotFoundException;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;

/**
 * High-level API for app code / Facade.
 */
class ZktecoAdmsManager
{
    public function __construct(
        private AdmsService $adms,
        private CommandService $commands,
        private UserSyncService $users,
        private DeviceIdentityService $identity,
    ) {
    }

    public function adms(): AdmsService
    {
        return $this->adms;
    }

    public function commands(): CommandService
    {
        return $this->commands;
    }

    public function users(): UserSyncService
    {
        return $this->users;
    }

    public function identity(): DeviceIdentityService
    {
        return $this->identity;
    }

    public function device(string $serial): ?ZktecoDevice
    {
        return ZktecoDevice::where('serial', $serial)->first();
    }

    public function addUser(ZktecoDevice|string $device, array $user): ZktecoDeviceCommand
    {
        return $this->commands->addUser($this->resolve($device), $user);
    }

    /**
     * @param  array<int,array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string}>  $users
     * @return array<int,ZktecoDeviceCommand>
     */
    public function addUsers(ZktecoDevice|string $device, array $users): array
    {
        return $this->commands->addUsers($this->resolve($device), $users);
    }

    public function deleteUser(ZktecoDevice|string $device, string|int $pin): ZktecoDeviceCommand
    {
        return $this->commands->deleteUser($this->resolve($device), $pin);
    }

    public function addFingerprint(ZktecoDevice|string $device, array $fp): ZktecoDeviceCommand
    {
        return $this->commands->addFingerprint($this->resolve($device), $fp);
    }

    public function deleteFingerprint(ZktecoDevice|string $device, string|int $pin, int $fid = 0): ZktecoDeviceCommand
    {
        return $this->commands->deleteFingerprint($this->resolve($device), $pin, $fid);
    }

    public function addFace(ZktecoDevice|string $device, array $face): ZktecoDeviceCommand
    {
        return $this->commands->addFace($this->resolve($device), $face);
    }

    public function deleteFace(ZktecoDevice|string $device, string|int $pin, int $fid = 0): ZktecoDeviceCommand
    {
        return $this->commands->deleteFace($this->resolve($device), $pin, $fid);
    }

    public function queryUsers(ZktecoDevice|string $device, string|int $pin = ''): ZktecoDeviceCommand
    {
        return $this->commands->queryUsers($this->resolve($device), $pin);
    }

    public function queryAttlog(ZktecoDevice|string $device, ?string $start = null, ?string $end = null): ZktecoDeviceCommand
    {
        return $this->commands->queryAttlog($this->resolve($device), $start, $end);
    }

    public function reboot(ZktecoDevice|string $device): ZktecoDeviceCommand
    {
        return $this->commands->reboot($this->resolve($device));
    }

    public function syncTime(ZktecoDevice|string $device, ?DateTimeInterface $time = null): ZktecoDeviceCommand
    {
        return $this->commands->syncTime($this->resolve($device), $time);
    }

    public function info(ZktecoDevice|string $device): ZktecoDeviceCommand
    {
        return $this->commands->info($this->resolve($device));
    }

    public function check(ZktecoDevice|string $device): ZktecoDeviceCommand
    {
        return $this->commands->check($this->resolve($device));
    }

    public function clearLog(ZktecoDevice|string $device): ZktecoDeviceCommand
    {
        return $this->commands->clearLog($this->resolve($device));
    }

    public function clearData(ZktecoDevice|string $device): ZktecoDeviceCommand
    {
        return $this->commands->clearData($this->resolve($device));
    }

    public function resetStamps(ZktecoDevice|string $device): ZktecoDevice
    {
        return $this->commands->resetStamps($this->resolve($device));
    }

    protected function resolve(ZktecoDevice|string $device): ZktecoDevice
    {
        if ($device instanceof ZktecoDevice) {
            return $device;
        }

        $found = $this->device($device);
        if (!$found) {
            throw DeviceNotFoundException::forSerial($device);
        }

        return $found;
    }
}
