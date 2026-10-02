<?php

namespace App\Railway\Support;

final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0;

    /** Great-circle distance between two points in kilometres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Linear interpolation between two coordinates.
     *
     * @return array{lat: float, lng: float}
     */
    public static function lerp(float $lat1, float $lng1, float $lat2, float $lng2, float $fraction): array
    {
        return [
            'lat' => round($lat1 + ($lat2 - $lat1) * $fraction, 6),
            'lng' => round($lng1 + ($lng2 - $lng1) * $fraction, 6),
        ];
    }
}
