<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoTransaction extends Model
{
    protected $table = 'zkteco_transactions';

    protected $fillable = [
        'device_id', 'user_id', 'timestamp', 'status', 'verify',
        'source', 'terminal_sn',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }
}
