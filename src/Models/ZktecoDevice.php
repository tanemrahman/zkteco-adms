<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZktecoDevice extends Model
{
    protected $table = 'zkteco_devices';

    protected $fillable = [
        'name', 'alias', 'ip', 'port', 'model', 'serial', 'firmware',
        'push_version', 'platform', 'device_name', 'password', 'comm_key',
        'status', 'protocol', 'is_registered', 'timezone',
        'user_count', 'fp_count', 'face_count', 'transaction_count',
        'last_attlog_stamp', 'last_operlog_stamp', 'last_attphoto_stamp',
        'last_seen_at', 'registered_at',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_registered' => 'boolean',
        'last_seen_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(ZktecoTransaction::class, 'device_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(ZktecoDeviceCommand::class, 'device_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(ZktecoDeviceUser::class, 'device_id');
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(ZktecoHeartbeatLog::class, 'device_id');
    }

    public function protocolLogs(): HasMany
    {
        return $this->hasMany(ZktecoAdmsLog::class, 'device_id');
    }

    public function isOnline(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        $minutes = (int) config('zkteco-adms.online_threshold_minutes', 3);

        return $this->last_seen_at->gt(now()->subMinutes($minutes));
    }
}
