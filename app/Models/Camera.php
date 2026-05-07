<?php

namespace App\Models;

use Database\Factories\CameraFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Camera extends Model
{
    /** @use HasFactory<CameraFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'location',
        'rtsp_url',
        'ip_address',
        'port',
        'username',
        'password',
        'latitude',
        'longitude',
        'resolution_width',
        'resolution_height',
        'is_active',
        'last_seen_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];
}
