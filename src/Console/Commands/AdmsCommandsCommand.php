<?php

namespace TanemRahman\ZktecoAdms\Console\Commands;

use Illuminate\Console\Command;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;
use TanemRahman\ZktecoAdms\Services\CommandService;
use Illuminate\Support\Str;

class AdmsCommandsCommand extends Command
{
    protected $signature = 'zkteco-adms:commands
        {--sn= : Device serial}
        {--list : List pending/sent commands}
        {--requeue-stale : Requeue stuck "sent" commands}
        {--prune : Prune protocol + heartbeat logs + old attendance photos}
        {--enqueue= : info|reboot|check|sync-time|clear-log|clear-data|query-users}
        {--add-user= : PIN to add (requires --sn and optional --user-name)}
        {--user-name= : Name for --add-user}
        {--delete-user= : PIN to delete (requires --sn)}';

    protected $description = 'Inspect and enqueue ADMS device commands (users, reboot, sync-time, …).';

    public function handle(CommandService $commands): int
    {
        if ($this->option('requeue-stale')) {
            $this->info('Requeued ' . $commands->requeueStale() . ' stale command(s).');
        }

        if ($this->option('prune')) {
            $r = $commands->pruneLogs();
            $this->info("Pruned {$r['logs']} protocol log(s), {$r['heartbeats']} heartbeat(s), {$r['photos']} photo(s).");
        }

        if ($pin = $this->option('add-user')) {
            return $this->addUser($commands, (string) $pin);
        }

        if ($pin = $this->option('delete-user')) {
            return $this->deleteUser($commands, (string) $pin);
        }

        if ($enqueue = $this->option('enqueue')) {
            return $this->enqueue($commands, (string) $enqueue);
        }

        if ($this->option('list') || (!$this->option('requeue-stale') && !$this->option('prune'))) {
            $this->listQueue();
        }

        return self::SUCCESS;
    }

    private function resolveDevice(): ?ZktecoDevice
    {
        $sn = $this->option('sn');
        if (!$sn) {
            $this->error('--sn=<serial> is required.');

            return null;
        }

        $device = ZktecoDevice::where('serial', $sn)->first();
        if (!$device) {
            $this->error("No device with serial '{$sn}'.");

            return null;
        }

        return $device;
    }

    private function addUser(CommandService $commands, string $pin): int
    {
        $device = $this->resolveDevice();
        if (!$device) {
            return self::FAILURE;
        }

        $cmd = $commands->addUser($device, [
            'pin' => $pin,
            'name' => (string) ($this->option('user-name') ?: 'User ' . $pin),
            'privilege' => 0,
        ]);

        $this->info("Queued USERINFO #{$cmd->id} for PIN {$pin} on {$device->serial}.");

        return self::SUCCESS;
    }

    private function deleteUser(CommandService $commands, string $pin): int
    {
        $device = $this->resolveDevice();
        if (!$device) {
            return self::FAILURE;
        }

        $cmd = $commands->deleteUser($device, $pin);
        $this->info("Queued DELETE_USER #{$cmd->id} for PIN {$pin}.");

        return self::SUCCESS;
    }

    private function enqueue(CommandService $commands, string $kind): int
    {
        $device = $this->resolveDevice();
        if (!$device) {
            return self::FAILURE;
        }

        $cmd = match ($kind) {
            'info' => $commands->info($device),
            'reboot' => $commands->reboot($device),
            'check' => $commands->check($device),
            'clear-log' => $commands->clearLog($device),
            'clear-data' => $commands->clearData($device),
            'query-users' => $commands->queryUsers($device),
            'sync-time' => $commands->syncTime($device),
            default => null,
        };

        if (!$cmd) {
            $this->error("Unknown --enqueue '{$kind}'. Use: info|reboot|check|sync-time|clear-log|clear-data|query-users");

            return self::FAILURE;
        }

        $this->info("Queued #{$cmd->id} ({$cmd->type}) for {$device->serial}.");

        return self::SUCCESS;
    }

    private function listQueue(): void
    {
        $query = ZktecoDeviceCommand::query()
            ->whereIn('status', [ZktecoDeviceCommand::STATUS_PENDING, ZktecoDeviceCommand::STATUS_SENT])
            ->orderBy('serial')
            ->orderBy('id');

        if ($sn = $this->option('sn')) {
            $query->where('serial', $sn);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No pending or in-flight commands.');

            return;
        }

        $this->table(
            ['ID', 'Serial', 'Type', 'Status', 'Attempts', 'Sent At', 'Command'],
            $rows->map(fn (ZktecoDeviceCommand $c) => [
                $c->id,
                $c->serial,
                $c->type,
                $c->status,
                $c->attempts,
                optional($c->sent_at)->toDateTimeString() ?? '—',
                Str::limit($c->command, 50),
            ])
        );
    }
}
