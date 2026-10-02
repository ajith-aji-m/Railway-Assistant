<?php

return [

    /*
    | Which railway data provider to use: "mock" (seeded demo data) or
    | "railradar" (real API, see config/services.php → railradar).
    */
    'provider' => env('RAILWAY_PROVIDER', 'mock'),

    /*
    | Auto-refresh interval (seconds) per live screen; 0 disables polling.
    | RailRadar's free plan allows 1,000 requests/month (responses are cached
    | for 60s), so the station board refreshes manually and train details /
    | the live map every 5 minutes by default.
    */
    'auto_refresh_seconds' => [
        'mock' => [
            'station' => 60,
            'train' => 30,
            'map' => 15,
        ],
        'railradar' => [
            'station' => (int) env('RAILRADAR_AUTO_REFRESH_SECONDS', 0),
            'train' => (int) env('RAILRADAR_TRAIN_REFRESH_SECONDS', 300),
            'map' => (int) env('RAILRADAR_MAP_REFRESH_SECONDS', 300),
        ],
    ],

    /* Minimum query length before a station search reaches the provider. */
    'search_min_length' => 2,

    'nearby' => [
        'radius_km' => (float) env('RAILWAY_NEARBY_RADIUS_KM', 50),
        'limit' => 5,
    ],

    'mock' => [
        /*
        | Simulated time of day for the mock live feed ("HH:MM"). The clock
        | starts at this time and advances with the real minutes of the current
        | hour, looping every hour, so trains visibly move during a demo.
        | Set to an empty value to use the real clock instead.
        */
        'clock' => env('RAILWAY_MOCK_CLOCK', '08:00'),
    ],

];
