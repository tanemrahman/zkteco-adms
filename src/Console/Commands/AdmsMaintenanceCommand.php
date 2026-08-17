<?php

namespace TanemRahman\ZktecoAdms\Console\Commands;

use Illuminate\Console\Command;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Services\CommandService;

class AdmsMaintenanceCommand extends Command
{
    protected $signature = 'zkteco-adms:maintain
        {--requeue-stale : Requeue commands stuck in sent status}
        {--prune : Prune old protocol + heartbeat logs + attendance photos}
        {--list-devices : List registered ADMS devices}';

    protected $description = 'Maintain ZKTeco ADMS command queue and logs.';

    public function handle(CommandService $commands): int
    {
        if ($this->option('list-devices')) {
            $rows = ZktecoDevice::where('protocol', 'adms')
                ->orderByDesc('last_seen_at')
                ->get(['id', 'name', 'serial', 'ip', 'status', 'last_seen_at', 'transaction_count']);

            if ($rows->isEmpty()) {
                $this->info('No ADMS devices registered yet.');
            } else {
                $this->table(
                    ['ID', 'Name', 'Serial', 'IP', 'Status', 'Last seen', 'Txns'],
                    $rows->map(fn ($d) => [
                        $d->id,
                        $d->name,
                        $d->serial,
                        $d->ip,
                        $d->status ? 'on' : 'off',
                        optional($d->last_seen_at)->toDateTimeString(),
                        $d->transaction_count,
                    ])
                );
            }
        }

        if ($this->option('requeue-stale')) {
            $this->info('Requeued ' . $commands->requeueStale() . ' stale command(s).');
        }

        if ($this->option('prune')) {
            $result = $commands->pruneLogs();
            $this->info("Pruned {$result['logs']} protocol log(s), {$result['heartbeats']} heartbeat(s), {$result['photos']} photo(s).");
        }

        if (!$this->option('list-devices') && !$this->option('requeue-stale') && !$this->option('prune')) {
            $this->warn('Nothing to do. Use --list-devices, --requeue-stale, and/or --prune.');
        }

        return self::SUCCESS;
    }
}
