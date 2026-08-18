<?php

namespace TanemRahman\ZktecoAdms\Services;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use TanemRahman\ZktecoAdms\Events\CommandCompleted;
use TanemRahman\ZktecoAdms\Models\ZktecoAdmsLog;
use TanemRahman\ZktecoAdms\Models\ZktecoAttphoto;
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

    public function recordReply(array $reply, ?ZktecoDevice $device = null): ?ZktecoDeviceCommand
    {
        $id = $reply['ID'] ?? $reply['id'] ?? null;
        if ($id === null) {
            return null;
        }

        $query = ZktecoDeviceCommand::query()->whereKey((int) $id);
        if ($device) {
            $query->where(function ($q) use ($device) {
                $q->where('device_id', $device->id)
                    ->orWhere('serial', $device->serial);
            });
        }

        $command = $query->first();
        if (!$command) {
            return null;
        }

        $return = isset($reply['Return']) ? (int) $reply['Return'] : null;

        $status = ($return === null || $return >= 0)
            ? ZktecoDeviceCommand::STATUS_DONE
            : ZktecoDeviceCommand::STATUS_FAILED;

        $command->forceFill([
            'return_code' => $return,
            'return_value' => $reply['CMD'] ?? $reply['Cmd'] ?? null,
            'status' => $status,
            'completed_at' => now(),
        ])->save();

        // Mirror roster / biometrics only after the device confirms success.
        if ($status === ZktecoDeviceCommand::STATUS_DONE) {
            $this->applySuccessfulCommand($command, $device);
        }

        event(new CommandCompleted($command));

        return $command;
    }

    /**
     * Apply local DB side-effects once the device reports Return ≥ 0.
     * Avoids optimistic roster rows that diverge when the device rejects a command.
     */
    protected function applySuccessfulCommand(ZktecoDeviceCommand $command, ?ZktecoDevice $device = null): void
    {
        $device ??= $command->device;

        if (! $device && $command->device_id) {
            $device = ZktecoDevice::query()->find($command->device_id);
        }

        if (! $device && $command->serial) {
            $device = ZktecoDevice::query()->where('serial', $command->serial)->first();
        }

        if (! $device) {
            return;
        }

        $body = (string) $command->command;

        if (str_starts_with($body, 'DATA UPDATE USERINFO ')) {
            $fields = $this->parseCommandFields(substr($body, strlen('DATA UPDATE USERINFO ')));
            if (($fields['PIN'] ?? '') === '') {
                return;
            }
            $user = app(AdmsService::class)->upsertDeviceUser($device, $fields);
            // Successful push means the user is enrolled on the device again.
            if ((bool) ($user->is_blocked ?? false)) {
                $user->forceFill(['is_blocked' => false])->save();
            }
            $device->forceFill([
                'user_count' => ZktecoDeviceUser::where('serial', $device->serial)
                    ->where(fn ($q) => $q->where('is_blocked', false)->orWhereNull('is_blocked'))
                    ->count(),
            ])->saveQuietly();

            return;
        }

        if (str_starts_with($body, 'DATA DELETE USERINFO ')) {
            $fields = $this->parseCommandFields(substr($body, strlen('DATA DELETE USERINFO ')));
            $pin = (string) ($fields['PIN'] ?? '');
            if ($pin === '') {
                return;
            }

            $user = ZktecoDeviceUser::query()
                ->where('serial', $device->serial)
                ->where('pin', $pin)
                ->first();

            // Soft-block: keep the local roster row so apps can unblock later.
            if ($user && (bool) ($user->is_blocked ?? false)) {
                $user->forceFill([
                    'has_fp' => false,
                    'has_face' => false,
                    'fp_count' => 0,
                    'face_count' => 0,
                    'synced_at' => now(),
                ])->save();
            } else {
                ZktecoDeviceUser::where('serial', $device->serial)->where('pin', $pin)->delete();
            }

            $device->forceFill([
                'user_count' => ZktecoDeviceUser::where('serial', $device->serial)
                    ->where(fn ($q) => $q->where('is_blocked', false)->orWhereNull('is_blocked'))
                    ->count(),
            ])->saveQuietly();

            return;
        }

        if (str_starts_with($body, 'DATA UPDATE FINGERTMP ')) {
            $fields = $this->parseCommandFields(substr($body, strlen('DATA UPDATE FINGERTMP ')));
            app(AdmsService::class)->markTemplate($device, 'FINGERTMP', $fields);

            return;
        }

        if (str_starts_with($body, 'DATA DELETE FINGERTMP ')) {
            $fields = $this->parseCommandFields(substr($body, strlen('DATA DELETE FINGERTMP ')));
            $this->decrementTemplateFlag($device, (string) ($fields['PIN'] ?? ''), 'fp', (string) ($fields['FID'] ?? '0'));

            return;
        }

        if (str_starts_with($body, 'DATA UPDATE FACE ')) {
            $fields = $this->parseCommandFields(substr($body, strlen('DATA UPDATE FACE ')));
            app(AdmsService::class)->markTemplate($device, 'FACE', $fields);

            return;
        }

        if (str_starts_with($body, 'DATA DELETE FACE ')) {
            $fields = $this->parseCommandFields(substr($body, strlen('DATA DELETE FACE ')));
            $this->decrementTemplateFlag($device, (string) ($fields['PIN'] ?? ''), 'face', (string) ($fields['FID'] ?? '0'));
        }
    }

    /**
     * @return array<string,string>
     */
    protected function parseCommandFields(string $body): array
    {
        $fields = [];

        foreach (preg_split('/\t+/', trim($body)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $fields[trim($key)] = $value;
        }

        return $fields;
    }

    protected function decrementTemplateFlag(ZktecoDevice $device, string $pin, string $kind, string $fid = '0'): void
    {
        if ($pin === '') {
            return;
        }

        $user = ZktecoDeviceUser::query()
            ->where('serial', $device->serial)
            ->where('pin', $pin)
            ->first();

        if (! $user) {
            return;
        }

        $fidsColumn = $kind === 'fp' ? 'fp_fids' : 'face_fids';
        $countColumn = $kind === 'fp' ? 'fp_count' : 'face_count';
        $hasColumn = $kind === 'fp' ? 'has_fp' : 'has_face';

        // Host app may not have run the FID migration yet — degrade to a plain decrement
        // rather than throwing (see AdmsService::supportsTemplateFids).
        if (AdmsService::supportsTemplateFids()) {
            $fids = array_values(array_filter($user->{$fidsColumn} ?? [], fn ($f) => (string) $f !== $fid));
            $user->{$fidsColumn} = $fids;
            $user->{$countColumn} = count($fids);
        } else {
            $user->{$countColumn} = max(0, (int) $user->{$countColumn} - 1);
        }

        $user->{$hasColumn} = (int) $user->{$countColumn} > 0;

        $user->synced_at = now();
        $user->save();
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
        $photoDays = (int) config('zkteco-adms.logging.photo_retention_days', 30);

        $logs = ZktecoAdmsLog::where('created_at', '<', now()->subDays($logDays))->delete();
        $heartbeats = ZktecoHeartbeatLog::where('created_at', '<', now()->subDays($hbDays))->delete();
        $photos = $this->prunePhotos($photoDays);

        return compact('logs', 'heartbeats', 'photos');
    }

    /** Deletes the stored image file alongside its row — a DB-only delete would just leak disk space. */
    protected function prunePhotos(int $days): int
    {
        $deleted = 0;

        ZktecoAttphoto::where('created_at', '<', now()->subDays($days))
            ->chunkById(200, function (Collection $photos) use (&$deleted) {
                foreach ($photos as $photo) {
                    if ($photo->disk && $photo->path) {
                        Storage::disk($photo->disk)->delete($photo->path);
                    }
                    $photo->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    /*
    |--------------------------------------------------------------------------
    | Command builders (body only — no C:<id>: prefix)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string, verify?:int|string|null, verify_mode?:int|string|null}  $user
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

        $verify = $user['verify_mode'] ?? $user['verify'] ?? null;
        if ($verify !== null && $verify !== '') {
            $fields[] = 'Verify=' . (int) $verify;
        }

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
     * Queue USERINFO to the device. Local `zkteco_device_users` is updated only
     * after a successful `devicecmd` reply (see applySuccessfulCommand).
     *
     * @param  array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string, verify?:int|string|null, verify_mode?:int|string|null}  $user
     */
    public function addUser(ZktecoDevice $device, array $user): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildUpdateUser($user), 'USERINFO');
    }

    /**
     * Queue many USERINFO updates (one command per user).
     *
     * @param  array<int,array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string, verify?:int|string|null, verify_mode?:int|string|null}>  $users
     * @return array<int,ZktecoDeviceCommand>
     */
    public function addUsers(ZktecoDevice $device, array $users): array
    {
        $commands = [];
        foreach ($users as $user) {
            $commands[] = $this->addUser($device, $user);
        }

        return $commands;
    }

    /**
     * Soft-block punches: remove the user from the device but keep the local roster
     * row with `is_blocked=true` so apps can unblock later.
     */
    public function blockUser(ZktecoDevice $device, string|int $pin): ZktecoDeviceCommand
    {
        $pin = (string) $pin;

        $user = ZktecoDeviceUser::query()
            ->where('serial', $device->serial)
            ->where('pin', $pin)
            ->first();

        if ($user) {
            $user->forceFill(['is_blocked' => true])->save();
        } else {
            ZktecoDeviceUser::query()->create([
                'device_id' => $device->id,
                'serial' => $device->serial,
                'pin' => $pin,
                'is_blocked' => true,
                'synced_at' => now(),
            ]);
        }

        return $this->deleteUser($device, $pin);
    }

    /**
     * Clear the soft-block flag and re-queue USERINFO from the local roster row.
     */
    public function unblockUser(ZktecoDevice $device, string|int $pin): ZktecoDeviceCommand
    {
        $pin = (string) $pin;

        $user = ZktecoDeviceUser::query()
            ->where('serial', $device->serial)
            ->where('pin', $pin)
            ->firstOrFail();

        $user->forceFill(['is_blocked' => false])->save();

        return $this->addUser($device, [
            'pin' => $user->pin,
            'name' => (string) ($user->name ?? ''),
            'privilege' => (int) ($user->privilege ?? 0),
            'password' => (string) ($user->password ?? ''),
            'card' => (string) ($user->card ?? ''),
            'group' => $user->group ?? 1,
            'timezone' => $user->timezone ?? '0000000000000000',
            'verify_mode' => $user->verify_mode,
        ]);
    }

    public function deleteUser(ZktecoDevice $device, string|int $pin): ZktecoDeviceCommand
    {
        return $this->enqueue($device, $this->buildDeleteUser($pin), 'DELETE_USER');
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
        return $this->enqueue($device, $this->buildUpdateFingerprint($fp), 'FINGERTMP');
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
        return $this->enqueue($device, $this->buildUpdateFace($face), 'FACE');
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
