<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoTransaction extends Model
{
    protected $table = 'zkteco_transactions';

    protected $fillable = [
        'device_id', 'user_id', 'timestamp', 'status', 'verify', 'workcode',
        'source', 'terminal_sn',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }

    public function scopeAdms(Builder $query): Builder
    {
        return $query->where('source', 'adms');
    }

    public function scopeForPin(Builder $query, int|string $pin): Builder
    {
        return $query->where('user_id', (int) $pin);
    }

    public function scopeOnDate(Builder $query, $date): Builder
    {
        return $query->whereDate('timestamp', $date);
    }
}
