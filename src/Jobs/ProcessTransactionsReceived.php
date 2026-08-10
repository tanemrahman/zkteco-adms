<?php

namespace TanemRahman\ZktecoAdms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TanemRahman\ZktecoAdms\Events\TransactionsReceived;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;

class ProcessTransactionsReceived implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int,int>  $pins
     * @param  array{saved:int,duplicates:int,rejected:int}  $summary
     * @param  array<int,array{pin:int|string,timestamp:string,status:int,verify:int}>  $records
     */
    public function __construct(
        public int $deviceId,
        public int $saved,
        public array $pins,
        public array $summary = [],
        public array $records = [],
        public string $source = 'adms',
    ) {
        $this->onQueue((string) config('zkteco-adms.attendance.queue', 'default'));
    }

    public function handle(): void
    {
        $device = ZktecoDevice::find($this->deviceId);
        if (!$device) {
            return;
        }

        event(new TransactionsReceived(
            $device,
            $this->saved,
            $this->pins,
            $this->source,
            $this->summary,
            $this->records,
        ));
    }
}
