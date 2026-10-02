<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Train extends Model
{
    protected $fillable = [
        'number', 'name', 'type', 'origin_station_id', 'destination_station_id',
        'has_pantry', 'zone', 'image_path', 'route_geometry',
    ];

    protected function casts(): array
    {
        return [
            'has_pantry' => 'boolean',
            'route_geometry' => 'array',
        ];
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'origin_station_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'destination_station_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TrainStop::class)->orderBy('sequence');
    }

    public function liveStatus(): HasOne
    {
        return $this->hasOne(TrainLiveStatus::class);
    }
}
