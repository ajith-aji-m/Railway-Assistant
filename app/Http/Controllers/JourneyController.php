<?php

namespace App\Http\Controllers;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Data\StationSummary;
use App\Railway\Exceptions\RailwayDataException;
use App\Railway\Support\LocalStationDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * From → To journey search. From suggestions are the user's nearby stations (local
 * directory, Haversine — no API request); To is found by typing (the same station
 * search, debounce and minimum length as the Stations screen). Trains are looked up
 * only once both stations are chosen.
 */
class JourneyController extends Controller
{
    public function __construct(
        private readonly RailwayProvider $railway,
        private readonly LocalStationDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        $code = ['nullable', 'string', 'regex:/^[A-Za-z0-9]{1,10}$/'];
        $validated = $request->validate([
            'from' => $code,
            'to' => $code,
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $from = isset($validated['from']) ? strtoupper($validated['from']) : null;
        $to = isset($validated['to']) ? strtoupper($validated['to']) : null;
        $hasLocation = isset($validated['lat'], $validated['lng']);
        $query = trim($validated['q'] ?? '');

        // Same search rules as the Stations screen (RailRadar quota: 2 chars / 400 ms).
        $isMock = config('railway.provider') === 'mock';
        $minLength = $isMock ? 1 : (int) config('railway.search_min_length', 2);
        $search = fn (): array => once(function () use ($query, $minLength): array {
            if ($query === '' || mb_strlen($query) < $minLength) {
                return [null, null];
            }
            try {
                return [$this->railway->searchStations($query), null];
            } catch (RailwayDataException $e) {
                return [null, $e->getMessage()];
            }
        });

        // Trains only once both ends are chosen (and differ).
        $journeys = fn (): array => once(function () use ($from, $to): array {
            if ($from === null || $to === null) {
                return [null, null];
            }
            if ($from === $to) {
                return [null, 'From and To must be different stations.'];
            }
            try {
                return [$this->railway->journeys($from, $to), null];
            } catch (RailwayDataException $e) {
                report($e);

                return [null, $e->getMessage()];
            }
        });

        return Inertia::render('Journey/Index', [
            'location' => $hasLocation ? ['lat' => (float) $validated['lat'], 'lng' => (float) $validated['lng']] : null,
            // From suggestions: nearest first, from the local directory (never an API request).
            'nearby' => fn () => $hasLocation
                ? $this->railway->nearbyStations((float) $validated['lat'], (float) $validated['lng'], (float) config('railway.nearby.radius_km'), (int) config('railway.nearby.limit'))
                : null,
            'query' => $query,
            'searchResults' => fn () => $search()[0],
            'searchError' => fn () => $search()[1],
            'searchMinLength' => $minLength,
            'searchDebounceMs' => $isMock ? 250 : 400,
            'from' => $from !== null ? $this->stationRef($from) : null,
            'to' => $to !== null ? $this->stationRef($to) : null,
            'journeys' => fn () => $journeys()[0],
            'journeyError' => fn () => $journeys()[1],
            'today' => $this->railway->now()->toDateString(),
        ]);
    }

    /** Name for a station code in the URL, from the local directory (no API request). */
    private function stationRef(string $code): StationSummary
    {
        $station = $this->directory->find([$code])[0] ?? null;

        return $station ? $this->directory->summary($station) : new StationSummary($code, $code, null, null, null, null);
    }
}
