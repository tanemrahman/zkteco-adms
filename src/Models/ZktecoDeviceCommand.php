<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoDeviceCommand extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'zkteco_device_commands';

    protected $fillable = [
        'device_id', 'serial', 'type', 'command', 'status',
        'return_code', 'return_value', 'attempts', 'sent_at', 'completed_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }

    /** Wire format: C:<id>:<command> */
    public function toWire(): string
    {
        return 'C:' . $this->id . ':' . $this->command;
    }
}
