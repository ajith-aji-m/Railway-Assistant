<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mock live feed for a train (cancellation, GPS availability, per-stop delays).
 */
class TrainLiveStatus extends Model
{
    protected $fillable = ['train_id', 'status', 'gps_status', 'stop_delays'];

    protected function casts(): array
    {
        return [
            'stop_delays' => 'array',
        ];
    }

    public function train(): BelongsTo
    {
        return $this->belongsTo(Train::class);
    }

    public function delayAt(int $sequence): int
    {
        return (int) ($this->stop_delays[(string) $sequence] ?? 0);
    }
}
