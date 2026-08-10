<?php

namespace TanemRahman\ZktecoAdms\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TanemRahman\ZktecoAdms\Events\TransactionsReceived;
use TanemRahman\ZktecoAdms\Models\ZktecoAdmsLog;
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

        return $expected === '' || hash_equals($expected, $provided);
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
        $deviceTz = config('zkteco-adms.attendance.device_timezone') ?: sprintf('%+03d:00', $tzHours);

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
            $pin = (int) $r['pin'];
            $key = $device->id . '_' . $pin . '_' . $normalized;

            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            $seen[$key] = true;
            $pins[$pin] = $pin;

            $rows[] = [
                'device_id' => $device->id,
                'user_id' => $pin,
                'timestamp' => $normalized,
                'status' => $r['status'],
                'verify' => $r['verify'],
                'source' => $source,
                'terminal_sn' => $device->serial,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $toInsert = [];
            foreach ($chunk as $row) {
                $punchAt = Carbon::parse($row['timestamp']);
                $exists = ZktecoTransaction::where('device_id', $row['device_id'])
                    ->where('user_id', $row['user_id'])
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

            if (!empty($toInsert)) {
                $affected = DB::table('zkteco_transactions')->insertOrIgnore($toInsert);
                $saved += $affected;
                $duplicates += (count($toInsert) - $affected);
            }
        }

        if ($saved > 0) {
            $device->increment('transaction_count', $saved);
            event(new TransactionsReceived($device, $saved, array_values($pins), $source));
        }

        return compact('saved', 'duplicates', 'rejected') + ['pins' => array_values($pins)];
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

        return ZktecoDeviceUser::updateOrCreate(
            ['serial' => $device->serial, 'pin' => (string) $pin],
            [
                'device_id' => $device->id,
                'name' => $fields['Name'] ?? null,
                'privilege' => isset($fields['Pri']) ? (int) $fields['Pri'] : 0,
                'password' => $fields['Passwd'] ?? null,
                'card' => $fields['Card'] ?? null,
                'group' => $fields['Grp'] ?? null,
                'timezone' => $fields['TZ'] ?? null,
                'synced_at' => now(),
            ]
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

        if (in_array($tag, ['FP', 'FINGERPRINT', 'BIODATA_FP'], true)) {
            $user->has_fp = true;
            $user->fp_count = (int) $user->fp_count + 1;
        } elseif (in_array($tag, ['FACE', 'BIOPHOTO', 'BIODATA_FACE'], true)) {
            $user->has_face = true;
            $user->face_count = (int) $user->face_count + 1;
        }

        $user->synced_at = now();
        $user->save();
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
