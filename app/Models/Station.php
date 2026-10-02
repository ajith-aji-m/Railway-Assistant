<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = [
        'code', 'name', 'full_name', 'city', 'state', 'zone', 'lat', 'lng', 'platforms', 'image_path', 'facilities',
        'is_active', 'aliases',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'platforms' => 'integer',
            'facilities' => 'array',
            'is_active' => 'boolean',
            'aliases' => 'array',
        ];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TrainStop::class);
    }
}
