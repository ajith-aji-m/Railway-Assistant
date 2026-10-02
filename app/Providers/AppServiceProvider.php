<?php

namespace App\Providers;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Mock\MockClock;
use App\Railway\Mock\MockRailwayProvider;
use App\Railway\RailRadar\RailRadarClient;
use App\Railway\RailRadar\RailRadarProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MockClock::class, fn () => new MockClock(config('railway.mock.clock')));
        $this->app->bind(RailRadarClient::class, fn () => RailRadarClient::fromConfig());

        $this->app->singleton(RailwayProvider::class, fn ($app) => match (config('railway.provider')) {
            'mock' => $app->make(MockRailwayProvider::class),
            'railradar' => $app->make(RailRadarProvider::class),
            default => throw new InvalidArgumentException('Unknown railway provider ['.config('railway.provider').'].'),
        });
    }

    public function boot(): void
    {
        //
    }
}
