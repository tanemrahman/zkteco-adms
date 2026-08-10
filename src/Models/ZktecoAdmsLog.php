<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;

class ZktecoAdmsLog extends Model
{
    public $timestamps = false;

    protected $table = 'zkteco_adms_logs';

    protected $fillable = [
        'device_id', 'serial', 'endpoint', 'method', 'table_name', 'level',
        'query', 'body', 'response', 'status_code', 'records_count',
        'message', 'ip', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
