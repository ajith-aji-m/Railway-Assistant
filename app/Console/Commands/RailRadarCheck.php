<?php

namespace App\Console\Commands;

use App\Railway\Exceptions\RailwayDataException;
use App\Railway\RailRadar\RailRadarClient;
use App\Railway\RailRadar\RailRadarNormalizer;
use Illuminate\Console\Command;

/**
 * Makes one real RailRadar live-status request and prints only safe, normalized fields.
 * Never prints the API key, request headers, or the raw response.
 */
class RailRadarCheck extends Command
{
    protected $signature = 'railradar:check
        {train? : 5-digit train number (defaults to RAILRADAR_TEST_TRAIN)}
        {--fresh : Bypass the local response cache (uses one API request)}
        {--json : Print the normalized live status as JSON}';

    protected $description = 'Check the RailRadar connection with one live train status request';

    public function handle(RailRadarClient $client, RailRadarNormalizer $normalizer): int
    {
        $number = (string) ($this->argument('train') ?: config('services.railradar.test_train'));

        if ($number === '') {
            $this->error('No train number given. Pass one, or set RAILRADAR_TEST_TRAIN in .env.');

            return self::INVALID;
        }

        try {
            $response = $client->liveTrain($number, fresh: (bool) $this->option('fresh'));
            $train = $normalizer->trainDetail($response['data'], $response['meta']);
        } catch (RailwayDataException $e) {
            $this->line('RailRadar: <error>Failed</error>');
            $this->line("Reason: {$e->reason}");
            $this->line('Message: '.$e->getMessage());
            if ($e->upstreamStatus) {
                $this->line("HTTP status: {$e->upstreamStatus}");
            }
            if ($e->traceId) {
                $this->line("Trace ID: {$e->traceId}");
            }

            return self::FAILURE;
        }

        $live = $train->live;
        $find = fn (?int $seq) => collect($live->stops)->firstWhere('sequence', $seq);
        $last = $find($live->lastStopSequence);
        $next = $find($live->nextStopSequence);

        $location = match (true) {
            $live->atStation && $last !== null => "At {$last->station->name} ({$last->station->code})",
            $last !== null => sprintf('%s km past %s (%s)', $live->distanceFromLastKm ?? '?', $last->station->name, $last->station->code),
            default => 'Unknown',
        };

        if ($this->option('json')) {
            $this->line(json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('RailRadar: <info>Connected</info>');
        $this->line("Train: {$train->number} – {$train->name}");
        $this->line("Route: {$train->from->name} ({$train->from->code}) → {$train->to->name} ({$train->to->code})");
        $this->line('Status: '.ucfirst($live->status->value));
        $this->line("Delay: {$live->delayMinutes} minutes");
        $this->line("Current location: {$location}");
        $this->line('Next station: '.($next ? sprintf('%s (%s) – %s km, ETA %s', $next->station->name, $next->station->code, $live->distanceToNextKm ?? '?', $next->expectedArrival ?? '?') : 'Unknown'));
        $this->line('Coordinates: '.($live->position ? "{$live->position['lat']}, {$live->position['lng']}" : 'Not provided'));
        $this->line('Speed: '.($live->speedKmh !== null ? "{$live->speedKmh} km/h" : 'Not provided'));
        $this->line('GPS: '.$live->gps->value);
        $this->line("Updated: {$live->updatedAt}");
        $this->line('Halts: '.count($live->stops).' · Route points: '.count($train->route));
        $this->line('Source: '.($response['meta']['source'] ?? '?').' · Trace ID: '.($response['meta']['traceId'] ?? '?'));

        return self::SUCCESS;
    }
}
