<?php

namespace TanemRahman\ZktecoAdms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;

class DeviceRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public ZktecoDevice $device)
    {
    }
}
