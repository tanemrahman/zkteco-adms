<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoDeviceUser extends Model
{
    protected $table = 'zkteco_device_users';

    protected $fillable = [
        'device_id', 'serial', 'pin', 'name', 'privilege', 'password',
        'card', 'group', 'timezone', 'verify_mode', 'is_blocked',
        'has_fp', 'has_face', 'fp_count', 'face_count', 'fp_fids', 'face_fids', 'synced_at',
    ];

    protected $casts = [
        'privilege' => 'integer',
        'verify_mode' => 'integer',
        'is_blocked' => 'boolean',
        'has_fp' => 'boolean',
        'has_face' => 'boolean',
        'fp_count' => 'integer',
        'face_count' => 'integer',
        'fp_fids' => 'array',
        'face_fids' => 'array',
        'synced_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }
}
