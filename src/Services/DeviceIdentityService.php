<?php

namespace TanemRahman\ZktecoAdms\Services;

use Illuminate\Http\Request;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;

/**
 * Capture / refresh device identity from handshake, OPTIONS and INFO payloads.
 */
class DeviceIdentityService
{
    public function __construct(private CommandService $commands)
    {
    }

    public function captureHandshake(ZktecoDevice $device, Request $request): void
    {
        $updates = [
            'is_registered' => true,
            'protocol' => 'adms',
            'last_seen_at' => now(),
        ];

        if ($device->registered_at === null) {
            $updates['registered_at'] = now();
        }

        if ($pushver = $request->query('pushver')) {
            $updates['push_version'] = (string) $pushver;
        }

        foreach (['DeviceName', '~DeviceName', 'MachineType', 'ProductName'] as $key) {
            $val = trim((string) $request->query($key, ''));
            if ($val !== '') {
                $updates['device_name'] = $val;
                $updates['model'] = $val;
                $this->maybeRenameFromDevice($device, $val, $updates);
                break;
            }
        }

        if ($platform = $request->query('Platform') ?: $request->query('~Platform')) {
            $updates['platform'] = (string) $platform;
            if (empty($updates['model']) && blank($device->model)) {
                $updates['model'] = (string) $platform;
            }
        }

        $device->forceFill($updates)->saveQuietly();
        $device->refresh();

        $this->requestIdentityIfNeeded($device);
    }

    public function captureOptions(ZktecoDevice $device, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }

        if (str_starts_with($raw, '~') || (str_contains($raw, ',') && str_contains($raw, '='))) {
            $this->applyKeyValues($device, str_replace(',', "\t", $raw));

            return;
        }

        $this->applyKeyValues($device, $raw);
    }

    /**
     * INFO string from getrequest INFO= or INFO command reply.
     * Common order: firmware, userCount, fpCount, transCount, ip, fpAlg, faceAlg, faceCount
     */
    public function syncInfo(ZktecoDevice $device, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }

        if ($this->looksLikeUserAgent($raw)) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();

            return;
        }

        if (str_contains($raw, '=')) {
            $this->applyKeyValues($device, $raw);

            return;
        }

        $parts = array_map('trim', explode(',', $raw));
        $updates = ['last_seen_at' => now()];

        if (isset($parts[0]) && $parts[0] !== '') {
            $updates['firmware'] = $parts[0];
            $this->applyInferredModel($device, $parts[0], $updates);
        }
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $updates['user_count'] = (int) $parts[1];
        }
        if (isset($parts[2]) && is_numeric($parts[2])) {
            $updates['fp_count'] = (int) $parts[2];
        }
        // parts[3] is device-side AttLogCount — do not overwrite app transaction_count
        if (isset($parts[4]) && filter_var($parts[4], FILTER_VALIDATE_IP)) {
            $updates['ip'] = $parts[4];
        }
        if (isset($parts[7]) && is_numeric($parts[7])) {
            $updates['face_count'] = (int) $parts[7];
        }

        $device->forceFill($updates)->saveQuietly();
    }

    private function applyKeyValues(ZktecoDevice $device, string $raw): void
    {
        $map = [
            'FWVersion' => 'firmware',
            'UserCount' => 'user_count',
            'FPCount' => 'fp_count',
            'FaceCount' => 'face_count',
            // Do NOT map AttLogCount/TransactionCount onto transaction_count —
            // that column tracks punches saved by this app, not device flash totals.
            'IPAddress' => 'ip',
            'DeviceName' => 'device_name',
            '~DeviceName' => 'device_name',
            'MachineType' => 'device_name',
            'ProductName' => 'device_name',
            'Platform' => 'platform',
            '~Platform' => 'platform',
            'PushVersion' => 'push_version',
        ];

        $updates = ['last_seen_at' => now()];

        foreach (preg_split('/[\t,]+|\s{2,}/', $raw) as $token) {
            $token = trim($token);
            if ($token === '' || !str_contains($token, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $token, 2);
            $k = trim($k);
            $v = trim($v);

            if (!isset($map[$k]) || $v === '') {
                continue;
            }

            $column = $map[$k];
            $updates[$column] = in_array($column, ['user_count', 'fp_count', 'face_count'], true)
                ? (int) $v
                : $v;
        }

        if (!empty($updates['firmware']) && is_string($updates['firmware'])) {
            $this->applyInferredModel($device, $updates['firmware'], $updates);
        }

        if (!empty($updates['device_name']) && is_string($updates['device_name'])) {
            $updates['model'] = $updates['device_name'];
            $this->maybeRenameFromDevice($device, $updates['device_name'], $updates);
        } elseif (!empty($updates['platform']) && is_string($updates['platform']) && blank($device->model) && empty($updates['model'])) {
            $updates['model'] = $updates['platform'];
        }

        $device->forceFill($updates)->saveQuietly();
    }

    private function applyInferredModel(ZktecoDevice $device, string $firmware, array &$updates): void
    {
        if (filled($device->device_name) || (!empty($updates['device_name']))) {
            return;
        }

        $inferred = $this->inferModelFromFirmware($firmware);
        if ($inferred === null) {
            return;
        }

        $updates['model'] = $inferred;
        $this->maybeRenameFromDevice($device, $inferred, $updates);
    }

    public function inferModelFromFirmware(string $firmware): ?string
    {
        $fw = trim($firmware);
        if ($fw === '' || preg_match('/^ver\s*\d/i', $fw)) {
            return null;
        }

        $map = [
            'ZAM230' => 'SpeedFace-V5L',
            'ZAM170' => 'SpeedFace-V5L',
            'ZAM210' => 'SpeedFace-V4L',
            'ZMM220' => 'SenseFace T1',
            'ZMM100' => 'SenseFace',
            'ZK6000' => 'ZKTeco Terminal',
        ];

        $upper = strtoupper($fw);
        foreach ($map as $needle => $label) {
            if (str_contains($upper, strtoupper($needle))) {
                return $label;
            }
        }

        $token = preg_split('/[-_\s]/', $fw)[0] ?? '';
        $token = trim($token);

        return $token !== '' ? $token : null;
    }

    private function maybeRenameFromDevice(ZktecoDevice $device, string $deviceName, array &$updates): void
    {
        $deviceName = trim($deviceName);
        if ($deviceName === '') {
            return;
        }

        $current = trim((string) ($device->name ?? ''));
        $serial = (string) $device->serial;
        $placeholder = $serial !== '' && strcasecmp($current, 'ADMS ' . $serial) === 0;

        if ($placeholder || $current === '' || $current === $serial) {
            $updates['name'] = $deviceName;
        }
    }

    private function looksLikeUserAgent(string $raw): bool
    {
        if (str_contains($raw, ',') || str_contains($raw, '=')) {
            return false;
        }

        return (bool) preg_match('/proxy|mozilla|curl\/|http|okhttp|python-requests/i', $raw);
    }

    private function requestIdentityIfNeeded(ZktecoDevice $device): void
    {
        $needs = blank($device->device_name)
            && (blank($device->firmware) || blank($device->model) || $this->hasPlaceholderName($device));

        if (!$needs) {
            return;
        }

        $alreadyQueued = ZktecoDeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('type', 'INFO')
            ->whereIn('status', [ZktecoDeviceCommand::STATUS_PENDING, ZktecoDeviceCommand::STATUS_SENT])
            ->exists();

        if ($alreadyQueued) {
            return;
        }

        $this->commands->info($device);
    }

    private function hasPlaceholderName(ZktecoDevice $device): bool
    {
        $name = trim((string) $device->name);
        $serial = (string) $device->serial;

        return $name === ''
            || $name === $serial
            || ($serial !== '' && strcasecmp($name, 'ADMS ' . $serial) === 0);
    }
}
