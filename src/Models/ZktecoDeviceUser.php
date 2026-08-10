<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoDeviceUser extends Model
{
    protected $table = 'zkteco_device_users';

    protected $fillable = [
        'device_id', 'serial', 'pin', 'name', 'privilege', 'password',
        'card', 'group', 'timezone', 'has_fp', 'has_face',
        'fp_count', 'face_count', 'synced_at',
    ];

    protected $casts = [
        'has_fp' => 'boolean',
        'has_face' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }
}
