<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;

class ZktecoHeartbeatLog extends Model
{
    public $timestamps = false;

    protected $table = 'zkteco_heartbeat_logs';

    protected $fillable = [
        'device_id', 'serial', 'ip', 'info', 'commands_sent', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
