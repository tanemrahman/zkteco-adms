<?php

namespace TanemRahman\ZktecoAdms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ZktecoAttphoto extends Model
{
    protected $table = 'zkteco_attphotos';

    protected $fillable = [
        'device_id', 'serial', 'pin', 'pin_raw', 'captured_at',
        'disk', 'path', 'size', 'cmd', 'stamp',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZktecoDevice::class, 'device_id');
    }

    public function url(): ?string
    {
        if (! $this->disk || ! $this->path) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    public function absolutePath(): ?string
    {
        if (! $this->disk || ! $this->path) {
            return null;
        }

        return Storage::disk($this->disk)->path($this->path);
    }
}
