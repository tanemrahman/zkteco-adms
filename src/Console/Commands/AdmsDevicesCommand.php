<?php

namespace TanemRahman\ZktecoAdms\Console\Commands;

use Illuminate\Console\Command;
use TanemRahman\ZktecoAdms\Models\ZktecoAdmsLog;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceUser;
use TanemRahman\ZktecoAdms\Models\ZktecoHeartbeatLog;
use TanemRahman\ZktecoAdms\Models\ZktecoTransaction;
use TanemRahman\ZktecoAdms\Services\CommandService;

class AdmsDevicesCommand extends Command
{
    protected $signature = 'zkteco-adms:devices
        {--list : List ADMS devices (default)}
        {--register= : Pre-register a serial before first connect}
        {--name= : Display name for --register}
        {--delete= : Comma-separated serial(s) to delete}
        {--reset-stamp= : Serial(s) or "all" — reset ATTLOG/OPERLOG stamps to force re-upload}';

    protected $description = 'List, pre-register, delete and reset ADMS devices.';

    public function handle(): int
    {
        if ($sn = $this->option('register')) {
            return $this->register((string) $sn);
        }

        if ($delete = $this->option('delete')) {
            return $this->deleteSerials((string) $delete);
        }

        if ($this->option('reset-stamp') !== null) {
            return $this->resetStamp((string) $this->option('reset-stamp'));
        }

        return $this->listDevices();
    }

    private function register(string $serial): int
    {
        $serial = trim($serial);
        if ($serial === '') {
            $this->error('--register requires a serial.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: 'ADMS ' . $serial));
        $tz = (int) config('zkteco-adms.options.timezone', 6);

        $device = ZktecoDevice::firstOrNew(['serial' => $serial]);
        $device->fill([
            'name' => $device->name ?: $name,
            'port' => $device->port ?: 443,
            'protocol' => 'adms',
            'status' => true,
            'is_registered' => true,
            'registered_at' => $device->registered_at ?? now(),
            'timezone' => $device->timezone ?: $tz,
            'last_attlog_stamp' => $device->last_attlog_stamp ?? '0',
            'last_operlog_stamp' => $device->last_operlog_stamp ?? '0',
        ]);
        $device->save();

        $this->info("Pre-registered '{$serial}' as #{$device->id}.");

        return self::SUCCESS;
    }

    private function resetStamp(string $raw): int
    {
        $serials = collect(explode(',', $raw))->map(fn ($s) => trim($s))->filter()->unique()->values();

        if ($serials->isEmpty()) {
            $this->error('--reset-stamp requires a serial or "all".');

            return self::FAILURE;
        }

        $devices = ($serials->count() === 1 && strtolower($serials[0]) === 'all')
            ? ZktecoDevice::where('protocol', 'adms')->get()
            : ZktecoDevice::whereIn('serial', $serials)->get();

        if ($devices->isEmpty()) {
            $this->error('No matching devices.');

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            app(CommandService::class)->resetStamps($device);
            $this->info("Reset stamps for '{$device->serial}' (#{$device->id}).");
        }

        return self::SUCCESS;
    }

    private function deleteSerials(string $raw): int
    {
        $serials = collect(explode(',', $raw))->map(fn ($s) => trim($s))->filter()->unique()->values();
        $deleted = 0;

        foreach ($serials as $serial) {
            $device = ZktecoDevice::where('serial', $serial)->first();
            if (!$device) {
                $this->warn("No device '{$serial}'.");
                continue;
            }

            $id = $device->id;
            ZktecoTransaction::where('device_id', $id)->delete();
            ZktecoDeviceUser::where('device_id', $id)->orWhere('serial', $serial)->delete();
            ZktecoDeviceCommand::where('device_id', $id)->orWhere('serial', $serial)->delete();
            ZktecoHeartbeatLog::where('device_id', $id)->orWhere('serial', $serial)->delete();
            ZktecoAdmsLog::where('device_id', $id)->orWhere('serial', $serial)->delete();
            $device->delete();
            $deleted++;
            $this->info("Deleted '{$serial}' (#{$id}).");
        }

        $this->info("Done. Deleted {$deleted} device(s).");

        return self::SUCCESS;
    }

    private function listDevices(): int
    {
        $rows = ZktecoDevice::where('protocol', 'adms')
            ->orderByDesc('last_seen_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No ADMS devices.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Serial', 'Model', 'Online', 'Users', 'FP', 'Face', 'Txns', 'Last seen'],
            $rows->map(fn (ZktecoDevice $d) => [
                $d->id,
                $d->name,
                $d->serial,
                $d->model ?: $d->device_name ?: '—',
                $d->isOnline() ? 'yes' : 'no',
                $d->user_count,
                $d->fp_count,
                $d->face_count,
                $d->transaction_count,
                optional($d->last_seen_at)->diffForHumans() ?? '—',
            ])
        );

        return self::SUCCESS;
    }
}
