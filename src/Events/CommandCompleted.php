<?php

namespace TanemRahman\ZktecoAdms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceCommand;

class CommandCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public ZktecoDeviceCommand $command)
    {
    }
}
