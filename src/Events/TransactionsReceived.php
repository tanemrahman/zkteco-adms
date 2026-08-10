<?php

namespace TanemRahman\ZktecoAdms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;

class TransactionsReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int,int>  $pins
     * @param  array{saved:int,duplicates:int,rejected:int}  $summary
     * @param  array<int,array{pin:int|string,timestamp:string,status:int,verify:int}>  $records
     */
    public function __construct(
        public ZktecoDevice $device,
        public int $saved,
        public array $pins,
        public string $source = 'adms',
        public array $summary = [],
        public array $records = [],
    ) {
    }
}
