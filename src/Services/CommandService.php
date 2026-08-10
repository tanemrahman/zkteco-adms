<?php

namespace TanemRahman\ZktecoAdms\Services;

use DateTimeInterface;
use Illuminate\Support\Collection;
use TanemRahman\ZktecoAdms\Models\ZktecoAdmsLog;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceUser;
use TanemRahman\ZktecoAdms\Models\ZktecoHeartbeatLog;

/**
 * ADMS command queue + builders for every common Push-SDK command.
 *
 * Wire format (getrequest response, one per line):
 *   C:<id>:<command body>
 *
 * Device reply (POST /iclock/devicecmd):
 *   ID=<id>&Return=<code>&CMD=<verb>
 */
class CommandService
{
    public function enqueue(ZktecoDevice $device, string $command, string $type = 'CUSTOM'): ZktecoDeviceCommand
    {
        return ZktecoDeviceCommand::create([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'type' => $type,
            'command' => $command,
            'status' => ZktecoDeviceCommand::STATUS_PENDING,
        ]);
    }

    public function pending(ZktecoDevice $device): Collection
    {
        $max = (int) config('zkteco-adms.commands.max_per_poll', 10);

        return ZktecoDeviceCommand::where('serial', $device->serial)
            ->where('status', ZktecoDeviceCommand::STATUS_PENDING)
            ->orderBy('id')
            ->limit($max)
            ->get();
    }

    public function dispatchToDevice(Collection $commands): string
    {
        $eol = config('zkteco-adms.responses.line_ending', "\n");
        $lines = [];

        foreach ($commands as $command) {
            $lines[] = $command->toWire();
            $command->forceFill([
                'status' => ZktecoDeviceCommand::STATUS_SENT,
                'sent_at' => now(),
                'attempts' => (int) $command->attempts + 1,
            ])->save();
        }

        return implode($eol, $lines) . $eol;
    }

    public function recordReply(array $reply): ?ZktecoDeviceCommand
    {
        $id = $reply['ID'] ?? $reply['id'] ?? null;
        if ($id === null) {
            return null;
        }

        $command = ZktecoDeviceCommand::find((int) $id);
        if (!$command) {
            return null;
        }

        $return = isset($reply['Return']) ? (int) $reply['Return'] : null;

        $command->forceFill([
            'return_code' => $return,
            'return_value' => $reply['CMD'] ?? null,
            'status' => ($return === null || $return >= 0)
                ? ZktecoDeviceCommand::STATUS_DONE
                : ZktecoDeviceCommand::STATUS_FAILED,
            'completed_at' => now(),
        ])->save();

        return $command;
    }

    public function requeueStale(): int
    {
        $minutes = (int) config('zkteco-adms.commands.stale_after_minutes', 30);

        return ZktecoDeviceCommand::where('status', ZktecoDeviceCommand::STATUS_SENT)
            ->where('sent_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => ZktecoDeviceCommand::STATUS_PENDING,
                'sent_at' => null,
            ]);
    }

    public function pruneLogs(): array
    {
        $logDays = (int) config('zkteco-adms.logging.retention_days', 14);
        $hbDays = (int) config('zkteco-adms.logging.heartbeat_retention_days', 3);

        $logs = ZktecoAdmsLog::where('created_at', '<', now()->subDays($logDays))->delete();
        $heartbeats = ZktecoHeartbeatLog::where('created_at', '<', now()->subDays($hbDays))->delete();

        return compact('logs', 'heartbeats');
    }

    /*
    |--------------------------------------------------------------------------
    | Command builders (body only — no C:<id>: prefix)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string}  $user
     */
    public function buildUpdateUser(array $user): string
    {
        $fields = [
            'PIN=' . ($user['pin'] ?? ''),
            'Name=' . ($user['name'] ?? ''),
            'Pri=' . ($user['privilege'] ?? 0),
            'Passwd=' . ($user['password'] ?? ''),
            'Card=' . ($user['card'] ?? ''),
            'Grp=' . ($user['group'] ?? 1),
            'TZ=' . ($user['timezone'] ?? '0000000000000000'),
        ];

        return 'DATA UPDATE USERINFO ' . implode("\t", $fields);
    }

    public function buildDeleteUser(string|int $pin): string
    {
        return 'DATA DELETE USERINFO PIN=' . $pin;
    }

    public function buildQueryUserInfo(string|int $pin = ''): string
    {
        return $pin === ''
            ? 'DATA QUERY USERINFO'
            : 'DATA QUERY USERINFO PIN=' . $pin;
    }

    /**
     * Push a fingerprint template to the device.
     *
     * @param  array{pin:string|int, fid?:int, size?:int, valid?:int, tmp:string}  $fp
     */
    public function buildUpdateFingerprint(array $fp): string
    {
        $fields = [
            'PIN=' . ($fp['pin'] ?? ''),
            'FID=' . ($fp['fid'] ?? 0),
            'Size=' . ($fp['size'] ?? strlen((string) ($fp['tmp'] ?? ''))),
            'Valid=' . ($fp['valid'] ?? 1),
            'TMP=' . ($fp['tmp'] ?? ''),
        ];

        return 'DATA UPDATE FINGERTMP ' . implode("\t", $fields);
    }

    public function buildDeleteFingerprint(string|int $pin, int $fid = 0): string
    {
        return 'DATA DELETE FINGERTMP PIN=' . $pin . "\tFID=" . $fid;
    }

    /**
     * Push a face template to the device.
     *
     * @param  array{pin:string|int, fid?:int, size?:int, valid?:int, tmp:string}  $face
     */
    public function buildUpdateFace(array $face): string
    {
        $fields = [
            'PIN=' . ($face['pin'] ?? ''),
            'FID=' . ($face['fid'] ?? 0),
            'Size=' . ($face['size'] ?? strlen((string) ($face['tmp'] ?? ''))),
            'VALID=' . ($face['valid'] ?? 1),
            'TMP=' . ($face['tmp'] ?? ''),
        ];

        return 'DATA UPDATE FACE ' . implode("\t", $fields);
    }

    public function buildDeleteFace(string|int $pin, int $fid = 0): string
    {
        return 'DATA DELETE FACE PIN=' . $pin . "\tFID=" . $fid;
    }

    public function buildQueryAttlog(?string $startTime = null, ?string $endTime = null): string
    {
        $parts = ['DATA QUERY ATTLOG'];
        if ($startTime) {
            $parts[] = 'StartTime=' . $startTime;
        }
        if ($endTime) {
            $parts[] = 'EndTime=' . $endTime;
        }

        return implode("\t", $parts);
    }

    public function buildInfo(): string
    {
        return 'INFO';
    }

    public function buildCheck(): string
    {
        return 'CHECK';
    }

    public function buildReboot(): string
    {
        return 'REBOOT';
    }

    public function buildClearLog(): string
    {
        return 'CLEAR LOG';
    }

    public function buildClearData(): string
    {
        return 'CLEAR DATA';
    }

    public function buildSetTime(DateTimeInterface $time): string
    {
        return 'SET OPTIONS DateTime=' . $this->encodeDeviceTime($time);
    }

    /**
     * ZKTeco encodes time as a single integer:
     * ((Y-2000)*12*31 + (M-1)*31 + (D-1)) * 86400 + H*3600 + Min*60 + S
     */
    public function encodeDeviceTime(DateTimeInterface $time): int
    {
        $y = (int) $time->format('Y');
        $m = (int) $time->format('n');
        $d = (int) $time->format('j');
        $h = (int) $time->format('G');
        $i = (int) $time->format('i');
        $s = (int) $time->format('s');

        return (($y - 2000) * 12 * 31 + ($m - 1) * 31 + ($d - 1)) * 86400
            + $h * 3600 + $i * 60 + $s;
    }

    /*
    |--------------------------------------------------------------------------
    | Convenience enqueue helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string}  $user
     */
    public function addUser(ZktecoDevice $device, array $user): ZktecoDeviceCommand
    {
        $cmd = $this->enqueue($device, $this->buildUpdateUser($user), 'USERINFO');

        // Mirror locally so UI is immediate; device confirms on next poll.
        ZktecoDeviceUser::updateOrCreate(
            ['serial' => $device->serial, 'pin' => (string) ($user['pin'] ?? '')],
            [
                'device_id' => $device->id,
                'name' => $user['name'] ?? null,
                'privilege' => (int) ($user['privilege'] ?? 0),
                'password' => $user['password'] ?? null,
                'card' => $user['card'] ?? null,
                'group' => isset($user['group']) ? (string) $user['group'] : '1',
                'timezone' => $user['timezone'] ?? '0000000000000000',
                'synced_at' => now(),
            ]
        );

        return $cmd;
    }

    public function deleteUser(ZktecoDevice $device, string|int $pin): ZktecoDeviceCommand
    {
        $cmd = $this->enqueue($device, $this->buildDeleteUser($pin), 'DELETE_USER');
        ZktecoDeviceUser::where('serial', $device->serial)->where('pin', (string) $pin)->delete();

        return $cmd;
    }

    public function queryUsers(ZktecoDevice $device, string|int $pin = ''): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildQueryUserInfo($pin), 'QUERY_USERS');
    }

    /**
     * @param  array{pin:string|int, fid?:int, size?:int, valid?:int, tmp:string}  $fp
     */
    public function addFingerprint(ZktecoDevice $device, array $fp): ZktecoDeviceCommand
    {
        $cmd = $this->enqueue($device, $this->buildUpdateFingerprint($fp), 'FINGERTMP');

        $user = ZktecoDeviceUser::firstOrNew([
            'serial' => $device->serial,
            'pin' => (string) ($fp['pin'] ?? ''),
        ]);
        $user->device_id = $device->id;
        $user->has_fp = true;
        $user->fp_count = (int) $user->fp_count + 1;
        $user->synced_at = now();
        $user->save();

        return $cmd;
    }

    public function deleteFingerprint(ZktecoDevice $device, string|int $pin, int $fid = 0): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildDeleteFingerprint($pin, $fid), 'DELETE_FP');
    }

    /**
     * @param  array{pin:string|int, fid?:int, size?:int, valid?:int, tmp:string}  $face
     */
    public function addFace(ZktecoDevice $device, array $face): ZktecoDeviceCommand
    {
        $cmd = $this->enqueue($device, $this->buildUpdateFace($face), 'FACE');

        $user = ZktecoDeviceUser::firstOrNew([
            'serial' => $device->serial,
            'pin' => (string) ($face['pin'] ?? ''),
        ]);
        $user->device_id = $device->id;
        $user->has_face = true;
        $user->face_count = (int) $user->face_count + 1;
        $user->synced_at = now();
        $user->save();

        return $cmd;
    }

    public function deleteFace(ZktecoDevice $device, string|int $pin, int $fid = 0): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildDeleteFace($pin, $fid), 'DELETE_FACE');
    }

    public function queryAttlog(ZktecoDevice $device, ?string $startTime = null, ?string $endTime = null): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildQueryAttlog($startTime, $endTime), 'QUERY_ATTLOG');
    }

    public function info(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildInfo(), 'INFO');
    }

    public function check(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildCheck(), 'CHECK');
    }

    public function reboot(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildReboot(), 'REBOOT');
    }

    public function clearLog(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildClearLog(), 'CLEAR_LOG');
    }

    public function clearData(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildClearData(), 'CLEAR_DATA');
    }

    public function syncTime(ZktecoDevice $device, ?DateTimeInterface $time = null): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildSetTime($time ?? now()), 'SET_TIME');
    }

    public function resetStamps(ZktecoDevice $device): ZktecoDevice
    {
        $device->forceFill([
            'last_attlog_stamp' => '0',
            'last_operlog_stamp' => '0',
            'last_attphoto_stamp' => '0',
        ])->save();

        return $device;
    }
}
