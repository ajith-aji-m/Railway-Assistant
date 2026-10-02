<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainStop extends Model
{
    protected $fillable = [
        'train_id', 'station_id', 'sequence', 'scheduled_arrival', 'scheduled_departure',
        'day_offset', 'platform', 'distance_km',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'day_offset' => 'integer',
            'distance_km' => 'integer',
        ];
    }

    public function train(): BelongsTo
    {
        return $this->belongsTo(Train::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
