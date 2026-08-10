<?php

namespace TanemRahman\ZktecoAdms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;

class TransactionsReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int,int>  $pins  Device PINs that punched in this batch
     */
    public function __construct(
        public ZktecoDevice $device,
        public int $saved,
        public array $pins,
        public string $source = 'adms',
    ) {
    }
}
