<?php

namespace TanemRahman\ZktecoAdms\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use TanemRahman\ZktecoAdms\Events\AttendancePhotoReceived;
use TanemRahman\ZktecoAdms\Events\DeviceRegistered;
use TanemRahman\ZktecoAdms\Events\TransactionsReceived;
use TanemRahman\ZktecoAdms\Jobs\ProcessTransactionsReceived;
use TanemRahman\ZktecoAdms\Models\ZktecoAdmsLog;
use TanemRahman\ZktecoAdms\Models\ZktecoAttphoto;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceUser;
use TanemRahman\ZktecoAdms\Models\ZktecoTransaction;

class AdmsService
{
    public function resolveDevice(string $serial, Request $request): ?ZktecoDevice
    {
        $serial = trim($serial);

        $device = ZktecoDevice::where('serial', $serial)->first();
        if ($device) {
            return $device;
        }

        if (!config('zkteco-adms.auto_register', true)) {
            return null;
        }

        $device = ZktecoDevice::create([
            'name' => 'ADMS ' . $serial,
            'serial' => $serial,
            'ip' => $request->ip(),
            'port' => 443,
            'protocol' => 'adms',
            'status' => true,
            'is_registered' => true,
            'registered_at' => now(),
            'last_seen_at' => now(),
            'timezone' => (int) config('zkteco-adms.options.timezone', 6),
        ]);

        Log::info('[ZKTeco ADMS] Registered device', ['serial' => $serial, 'id' => $device->id]);
        event(new DeviceRegistered($device));

        return $device;
    }

    public function touch(ZktecoDevice $device): void
    {
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function commKeyValid(ZktecoDevice $device, Request $request): bool
    {
        if (!config('zkteco-adms.require_comm_key', false)) {
            return true;
        }

        $provided = (string) $request->query('pushcommkey', $request->query('CommKey', ''));
        $expected = $device->comm_key ?: (string) config('zkteco-adms.comm_key', '');

        if ($expected === '') {
            // Fail closed when the feature is enabled but no key is configured.
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function buildInitOptions(ZktecoDevice $device): string
    {
        $opt = config('zkteco-adms.options');
        $eol = config('zkteco-adms.responses.line_ending', "\n");

        $attStamp = $device->last_attlog_stamp ?: '0';
        $opStamp = $device->last_operlog_stamp ?: '0';
        $photoStamp = $device->last_attphoto_stamp ?: '0';

        $lines = [
            'GET OPTION FROM: ' . $device->serial,
            'Stamp=' . $attStamp,
            'OpStamp=' . $opStamp,
            'ATTLOGStamp=' . $attStamp,
            'OPERLOGStamp=' . $opStamp,
            'ATTPHOTOStamp=' . $photoStamp,
            'ErrorDelay=' . $opt['error_delay'],
            'Delay=' . $opt['delay'],
            'TransTimes=' . $opt['trans_times'],
            'TransInterval=' . $opt['trans_interval'],
            'TransFlag=' . $opt['trans_flag'],
            'TimeZone=' . ($device->timezone ?? $opt['timezone']),
            'Realtime=' . $opt['realtime'],
            'Encrypt=' . $opt['encrypt'],
        ];

        if (!empty($opt['send_server_time'])) {
            $tzHours = (int) ($device->timezone ?? $opt['timezone'] ?? 6);
            $deviceNow = now()->utc()->addHours($tzHours);
            $lines[] = 'DateTime=' . $deviceNow->format('Y-m-d H:i:s');
            $lines[] = 'ServerVer=2.4.1';
            $lines[] = 'ServerName=' . ($opt['server_name'] ?? 'Laravel ADMS');
        }

        return implode($eol, $lines) . $eol;
    }

    public function parseAttlog(string $body): array
    {
        $records = [];

        foreach ($this->splitLines($body) as $line) {
            if (str_contains($line, "\t")) {
                $cols = explode("\t", $line);
            } else {
                $parts = preg_split('/\s+/', trim($line)) ?: [];
                if (count($parts) < 3) {
                    continue;
                }
                $cols = [
                    $parts[0],
                    $parts[1] . ' ' . $parts[2],
                    $parts[3] ?? '0',
                    $parts[4] ?? '0',
                    $parts[5] ?? null,
                ];
            }

            if (count($cols) < 2 || trim((string) $cols[0]) === '') {
                continue;
            }

            $records[] = [
                'pin' => trim((string) $cols[0]),
                'timestamp' => trim((string) $cols[1]),
                'status' => isset($cols[2]) && $cols[2] !== '' ? (int) $cols[2] : 0,
                'verify' => isset($cols[3]) && $cols[3] !== '' ? (int) $cols[3] : 0,
                'workcode' => isset($cols[4]) ? trim((string) $cols[4]) : null,
            ];
        }

        return $records;
    }

    /**
     * @return array{saved:int,duplicates:int,rejected:int,pins:array<int,int>}
     */
    public function storeAttlog(ZktecoDevice $device, array $records): array
    {
        $saved = 0;
        $duplicates = 0;
        $rejected = 0;
        $pins = [];

        $retentionDays = (int) config('zkteco-adms.attendance.retention_days', 30);
        $futureSkew = (int) config('zkteco-adms.attendance.future_skew_minutes', 360);
        $source = (string) config('zkteco-adms.attendance.source', 'adms');
        $tzHours = (int) ($device->timezone ?? config('zkteco-adms.options.timezone', 6));
        // Prefer explicit IANA zone only when set; otherwise use device hour offset.
        $configuredTz = config('zkteco-adms.attendance.device_timezone');
        $deviceTz = filled($configuredTz) ? (string) $configuredTz : sprintf('%+03d:00', $tzHours);

        $floor = $retentionDays > 0 ? now()->subDays($retentionDays)->startOfDay() : null;
        $ceil = now()->addMinutes($futureSkew);
        $tolerance = (int) config('zkteco-adms.attendance.dedup_tolerance_seconds', 5);

        $rows = [];
        $seen = [];

        foreach ($records as $r) {
            try {
                $ts = Carbon::parse($r['timestamp'], $deviceTz)->timezone(config('app.timezone', 'UTC'));
            } catch (\Throwable) {
                $rejected++;
                continue;
            }

            if ($ts->gt($ceil) || ($floor && $ts->lt($floor))) {
                $rejected++;
                continue;
            }

            $normalized = $ts->format('Y-m-d H:i:s');
            $rawPin = trim((string) $r['pin']);
            if ($rawPin === '' || !ctype_digit($rawPin)) {
                $rejected++;
                continue;
            }
            $pin = (int) $rawPin;
            $key = $device->id . '_' . $pin . '_' . $normalized . '_' . $r['status'];

            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'device_id' => $device->id,
                'user_id' => $pin,
                'timestamp' => $normalized,
                'status' => $r['status'],
                'verify' => $r['verify'],
                'workcode' => $this->normalizeWorkcode($r['workcode'] ?? null),
                'source' => $source,
                'terminal_sn' => $device->serial,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $insertedRecords = [];

        foreach (array_chunk($rows, 100) as $chunk) {
            $toInsert = [];
            foreach ($chunk as $row) {
                $punchAt = Carbon::parse($row['timestamp']);
                $exists = ZktecoTransaction::where('device_id', $row['device_id'])
                    ->where('user_id', $row['user_id'])
                    ->where('status', $row['status'])
                    ->whereBetween('timestamp', [
                        $punchAt->copy()->subSeconds($tolerance)->format('Y-m-d H:i:s'),
                        $punchAt->copy()->addSeconds($tolerance)->format('Y-m-d H:i:s'),
                    ])
                    ->exists();

                if ($exists) {
                    $duplicates++;
                } else {
                    $toInsert[] = $row;
                }
            }

            if (empty($toInsert)) {
                continue;
            }

            $affected = DB::table('zkteco_transactions')->insertOrIgnore($toInsert);
            $saved += $affected;
            $duplicates += (count($toInsert) - $affected);

            foreach ($toInsert as $row) {
                $existsNow = ZktecoTransaction::where('device_id', $row['device_id'])
                    ->where('user_id', $row['user_id'])
                    ->where('timestamp', $row['timestamp'])
                    ->where('status', $row['status'])
                    ->exists();

                if (!$existsNow) {
                    continue;
                }

                $pin = (int) $row['user_id'];
                $pins[$pin] = $pin;
                $insertedRecords[] = [
                    'pin' => $pin,
                    'timestamp' => $row['timestamp'],
                    'status' => $row['status'],
                    'verify' => $row['verify'],
                    'workcode' => $row['workcode'] ?? null,
                ];
            }
        }

        // Keep event payload length aligned with rows that exist after insert.
        if (count($insertedRecords) > $saved) {
            $insertedRecords = array_slice($insertedRecords, 0, $saved);
        }

        $summary = compact('saved', 'duplicates', 'rejected');

        if ($saved > 0) {
            $device->increment('transaction_count', $saved);
            $this->dispatchTransactionsEvent(
                $device,
                $saved,
                array_values($pins),
                $source,
                $summary,
                $insertedRecords,
            );
        }

        return $summary + ['pins' => array_values($pins)];
    }

    /**
     * @param  array<int,int>  $pins
     * @param  array{saved:int,duplicates:int,rejected:int}  $summary
     * @param  array<int,array{pin:int|string,timestamp:string,status:int,verify:int}>  $records
     */
    protected function dispatchTransactionsEvent(
        ZktecoDevice $device,
        int $saved,
        array $pins,
        string $source,
        array $summary,
        array $records,
    ): void {
        if (config('zkteco-adms.attendance.queue_processing', false)) {
            ProcessTransactionsReceived::dispatch(
                $device->id,
                $saved,
                $pins,
                $summary,
                $records,
                $source,
            );

            return;
        }

        event(new TransactionsReceived($device, $saved, $pins, $source, $summary, $records));
    }

    public function parseOperlog(string $body): array
    {
        $records = [];

        foreach ($this->splitLines($body) as $line) {
            $spacePos = strpos($line, ' ');
            if ($spacePos === false) {
                $records[] = ['tag' => strtoupper(trim($line)), 'fields' => [], 'raw' => $line];
                continue;
            }

            $tag = strtoupper(substr($line, 0, $spacePos));
            $rest = substr($line, $spacePos + 1);

            $records[] = [
                'tag' => $tag,
                'fields' => $this->parseKeyValues($rest),
                'raw' => $line,
            ];
        }

        return $records;
    }

    public function upsertDeviceUser(ZktecoDevice $device, array $fields): ZktecoDeviceUser
    {
        $pin = $fields['PIN'] ?? $fields['Pin'] ?? null;

        $attributes = [
            'device_id' => $device->id,
            'name' => $fields['Name'] ?? null,
            'privilege' => isset($fields['Pri']) ? (int) $fields['Pri'] : 0,
            'password' => $fields['Passwd'] ?? null,
            'card' => $fields['Card'] ?? null,
            'group' => $fields['Grp'] ?? null,
            'timezone' => $fields['TZ'] ?? null,
            'synced_at' => now(),
        ];

        if (array_key_exists('Verify', $fields) || array_key_exists('verify', $fields)) {
            $verify = $fields['Verify'] ?? $fields['verify'];
            $attributes['verify_mode'] = ($verify === null || $verify === '')
                ? null
                : (int) $verify;
        }

        // Never clear soft-block from device OPERLOG/USERINFO uploads.
        $existing = ZktecoDeviceUser::query()
            ->where('serial', $device->serial)
            ->where('pin', (string) $pin)
            ->first();

        if ($existing && (bool) ($existing->is_blocked ?? false)) {
            // Device re-uploaded the user — clear block only when explicitly pushed via unblock.
            // Keep is_blocked as-is so app-controlled block wins over stale OPERLOG.
        }

        return ZktecoDeviceUser::updateOrCreate(
            ['serial' => $device->serial, 'pin' => (string) $pin],
            $attributes
        );
    }

    public function markTemplate(ZktecoDevice $device, string $tag, array $fields): void
    {
        $pin = $fields['PIN'] ?? $fields['Pin'] ?? null;
        if ($pin === null) {
            return;
        }

        $user = ZktecoDeviceUser::firstOrNew([
            'serial' => $device->serial,
            'pin' => (string) $pin,
        ]);

        $user->device_id = $device->id;
        $fid = (string) ($fields['FID'] ?? $fields['Fid'] ?? 0);

        if (in_array($tag, ['FP', 'FINGERPRINT', 'FINGERTMP', 'BIODATA_FP'], true)) {
            $this->addTemplateFid($user, 'fp', $fid);
        } elseif (in_array($tag, ['FACE', 'BIOPHOTO', 'BIODATA_FACE', 'BIODATA'], true)) {
            // Bare BIODATA is usually face on modern Push firmware; FP uses FINGERTMP/FP.
            if ($tag === 'BIODATA' && isset($fields['Type']) && stripos((string) $fields['Type'], 'fp') !== false) {
                $this->addTemplateFid($user, 'fp', $fid);
            } else {
                $this->addTemplateFid($user, 'face', $fid);
            }
        }

        $user->synced_at = now();
        $user->save();
    }

    /**
     * Record a template by FID instead of blindly incrementing a counter, so
     * re-uploading the device's existing (unchanged) templates on every sync
     * does not inflate the count. Count reflects the number of distinct FIDs
     * currently known for this user.
     */
    private function addTemplateFid(ZktecoDeviceUser $user, string $kind, string $fid): void
    {
        $fidsColumn = $kind === 'fp' ? 'fp_fids' : 'face_fids';
        $countColumn = $kind === 'fp' ? 'fp_count' : 'face_count';
        $hasColumn = $kind === 'fp' ? 'has_fp' : 'has_face';

        // The FID columns arrive in a migration, which a host app may not have run yet.
        // Writing them blindly would throw on every template upload, so fall back to the
        // pre-1.4.3 counting instead of taking the whole sync down.
        if (! self::supportsTemplateFids()) {
            $user->{$countColumn} = (int) $user->{$countColumn} + 1;
            $user->{$hasColumn} = true;

            return;
        }

        $fids = $user->{$fidsColumn} ?? [];
        if (! in_array($fid, $fids, true)) {
            $fids[] = $fid;
        }

        $user->{$fidsColumn} = array_values($fids);
        $user->{$countColumn} = count($fids);
        $user->{$hasColumn} = count($fids) > 0;
    }

    /**
     * Whether the per-FID tracking columns exist. Resolved once per process — the answer
     * cannot change inside a request, and this runs on every uploaded template.
     */
    public static function supportsTemplateFids(): bool
    {
        static $supported = null;

        if ($supported === null) {
            try {
                $supported = Schema::hasColumn('zkteco_device_users', 'fp_fids');
            } catch (\Throwable) {
                $supported = false;
            }
        }

        return $supported;
    }

    public function updateStamp(ZktecoDevice $device, string $table, ?string $stamp): void
    {
        if ($stamp === null || $stamp === '') {
            return;
        }

        $column = match (strtoupper($table)) {
            'ATTLOG' => 'last_attlog_stamp',
            'OPERLOG' => 'last_operlog_stamp',
            'ATTPHOTO' => 'last_attphoto_stamp',
            default => null,
        };

        if ($column) {
            $device->forceFill([$column => $stamp])->saveQuietly();
        }
    }

    public function captureHandshake(ZktecoDevice $device, Request $request): void
    {
        $updates = [];
        if ($v = $request->query('pushver')) {
            $updates['push_version'] = (string) $v;
        }
        if ($v = $request->query('language')) {
            // informational only
        }
        if (!empty($updates)) {
            $device->forceFill($updates + [
                'is_registered' => true,
                'registered_at' => $device->registered_at ?: now(),
            ])->saveQuietly();
        }
    }

    public function syncInfo(ZktecoDevice $device, string $info): void
    {
        // INFO format often: "FWVersion=...,UserCount=..,FPCount=..,..."
        $parts = [];
        foreach (explode(',', $info) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            $parts[strtolower($k)] = $v;
        }

        $updates = [];
        if (isset($parts['fwversion']) || isset($parts['firmware'])) {
            $updates['firmware'] = $parts['fwversion'] ?? $parts['firmware'];
        }
        if (isset($parts['usercount'])) {
            $updates['user_count'] = (int) $parts['usercount'];
        }
        if (isset($parts['fpcount'])) {
            $updates['fp_count'] = (int) $parts['fpcount'];
        }
        if (isset($parts['facecount'])) {
            $updates['face_count'] = (int) $parts['facecount'];
        }

        if (!empty($updates)) {
            $device->forceFill($updates)->saveQuietly();
        }
    }

    public function ok(): string
    {
        return (string) config('zkteco-adms.responses.ok', 'OK');
    }

    public function dataOk(int $count): string
    {
        return str_replace('{count}', (string) $count, (string) config('zkteco-adms.responses.data_ok', 'OK: {count}'));
    }

    public function logRequest(array $data): void
    {
        if (!config('zkteco-adms.logging.enabled', true)) {
            return;
        }

        if (($data['endpoint'] ?? '') === 'getrequest' && !config('zkteco-adms.logging.log_heartbeats', false)) {
            return;
        }

        $max = (int) config('zkteco-adms.logging.max_body', 10000);

        foreach (['body', 'response', 'query'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && strlen($data[$field]) > $max) {
                $data[$field] = substr($data[$field], 0, $max) . '…';
            }
        }

        $data['created_at'] = now();
        $data['level'] = $data['level'] ?? 'info';

        try {
            ZktecoAdmsLog::create($data);
        } catch (\Throwable $e) {
            Log::warning('[ZKTeco ADMS] Failed to write protocol log: ' . $e->getMessage());
        }
    }

    /**
     * Push SDK ATTPHOTO body:
     *   PIN=YYYYMMDDHHNNSS-UID\tSN=…\tsize=N\tCMD=uploadphoto\0[JPEG bytes]
     *
     * @return array{
     *   pin:?string,
     *   pin_raw:string,
     *   captured_at:?\Carbon\Carbon,
     *   size:int,
     *   cmd:?string,
     *   sn:?string,
     *   binary:string
     * }|null
     */
    public function parseAttphoto(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $nullPos = strpos($body, "\0");
        if ($nullPos !== false) {
            $header = substr($body, 0, $nullPos);
            $binary = substr($body, $nullPos + 1);
        } else {
            $jpeg = strpos($body, "\xFF\xD8\xFF");
            if ($jpeg === false) {
                return null;
            }
            $header = substr($body, 0, $jpeg);
            $binary = substr($body, $jpeg);
        }

        $fields = [];
        foreach (preg_split('/[\t\s]+/', trim($header)) ?: [] as $part) {
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $fields[strtoupper(trim($k))] = trim($v);
        }

        $declared = isset($fields['SIZE']) ? (int) $fields['SIZE'] : 0;
        if ($declared > 0 && strlen($binary) >= $declared) {
            $binary = substr($binary, 0, $declared);
        }

        if ($binary === '') {
            return null;
        }

        $pinRaw = (string) ($fields['PIN'] ?? '');
        $userPin = null;
        $capturedAt = null;

        if (preg_match('/^(\d{14})-(\d+)$/', $pinRaw, $m)) {
            try {
                $capturedAt = Carbon::createFromFormat('YmdHis', $m[1]);
            } catch (\Throwable) {
                $capturedAt = null;
            }
            $userPin = $m[2];
        } elseif (preg_match('/^(\d{14})$/', $pinRaw, $m)) {
            try {
                $capturedAt = Carbon::createFromFormat('YmdHis', $m[1]);
            } catch (\Throwable) {
                $capturedAt = null;
            }
        } elseif ($pinRaw !== '' && ctype_digit($pinRaw)) {
            $userPin = $pinRaw;
        }

        return [
            'pin' => $userPin,
            'pin_raw' => $pinRaw,
            'captured_at' => $capturedAt,
            'size' => strlen($binary),
            'cmd' => $fields['CMD'] ?? null,
            'sn' => $fields['SN'] ?? null,
            'binary' => $binary,
        ];
    }

    /**
     * Persist an attendance photo to disk + `zkteco_attphotos`.
     *
     * @return array{saved:bool,photo:?ZktecoAttphoto,bytes:int,reason:?string}
     */
    public function storeAttphoto(ZktecoDevice $device, string $body, ?string $stamp = null): array
    {
        if (! config('zkteco-adms.attphoto.enabled', true)) {
            return ['saved' => false, 'photo' => null, 'bytes' => strlen($body), 'reason' => 'disabled'];
        }

        $parsed = $this->parseAttphoto($body);
        if (! $parsed) {
            return ['saved' => false, 'photo' => null, 'bytes' => strlen($body), 'reason' => 'parse_failed'];
        }

        $disk = (string) config('zkteco-adms.attphoto.disk', 'local');
        $base = trim((string) config('zkteco-adms.attphoto.path', 'zkteco/attphotos'), '/');
        $date = ($parsed['captured_at'] ?? now())->format('Y/m/d');
        $pinPart = $parsed['pin'] ?: 'unknown';
        $stampPart = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($stamp ?: now()->format('YmdHis')));
        $filename = sprintf('%s_%s_%s.jpg', $device->serial, $pinPart, $stampPart ?: uniqid('p'));
        $path = $base . '/' . $date . '/' . $filename;

        try {
            Storage::disk($disk)->put($path, $parsed['binary']);
        } catch (\Throwable $e) {
            Log::warning('[ZKTeco ADMS] ATTPHOTO save failed: ' . $e->getMessage());

            return ['saved' => false, 'photo' => null, 'bytes' => $parsed['size'], 'reason' => 'storage_failed'];
        }

        $photo = ZktecoAttphoto::create([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'pin' => $parsed['pin'],
            'pin_raw' => $parsed['pin_raw'] ?: null,
            'captured_at' => $parsed['captured_at'],
            'disk' => $disk,
            'path' => $path,
            'size' => $parsed['size'],
            'cmd' => $parsed['cmd'],
            'stamp' => $stamp,
        ]);

        event(new AttendancePhotoReceived($device, $photo));

        return ['saved' => true, 'photo' => $photo, 'bytes' => $parsed['size'], 'reason' => null];
    }

    protected function normalizeWorkcode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 64);
    }

    protected function splitLines(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
    }

    protected function parseKeyValues(string $rest): array
    {
        $fields = [];
        $pairs = preg_split('/\t+/', $rest) ?: [];

        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $fields[trim($k)] = trim($v);
        }

        return $fields;
    }
}
