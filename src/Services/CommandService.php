<?php

namespace TanemRahman\ZktecoAdms\Services;

use Illuminate\Support\Collection;
use TanemRahman\ZktecoAdms\Models\ZktecoAdmsLog;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;
use TanemRahman\ZktecoAdms\Models\ZktecoHeartbeatLog;

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

    /** Convenience helpers for common ADMS commands */
    public function reboot(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, 'REBOOT', 'REBOOT');
    }

    public function info(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, 'INFO', 'INFO');
    }

    public function clearLog(ZktecoDevice $device): ZktecoDeviceCommand
    {
        return $this->enqueue($device, 'CLEAR LOG', 'CLEAR_LOG');
    }
}
