<?php

namespace TanemRahman\ZktecoAdms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;

class UsersSynced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ZktecoDevice $device,
        public int $users,
        public int $templates,
        public string $table = 'OPERLOG',
    ) {
    }
}
