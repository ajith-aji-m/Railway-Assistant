<?php

namespace App\Http\Controllers;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Data\StationDetail;
use App\Railway\Enums\BoardType;
use App\Railway\Exceptions\RailwayDataException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    public function __construct(private readonly RailwayProvider $railway) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['nullable', 'numeric', 'in:50,100'],
            'q' => ['nullable', 'string', 'max:60'],
            'all' => ['nullable', 'boolean'],
        ]);

        $radius = (float) ($validated['radius'] ?? config('railway.nearby.radius_km'));
        $hasLocation = isset($validated['lat'], $validated['lng']);
        $query = trim($validated['q'] ?? '');
        $limit = ($validated['all'] ?? false) ? 20 : config('railway.nearby.limit');

        // Station search may reach an external API (RailRadar): short queries are not
        // searched there, and provider failures surface as a safe message.
        $isMock = config('railway.provider') === 'mock';
        $minLength = $isMock ? 1 : (int) config('railway.search_min_length', 2);
        // Lazy + memoized: runs only when search props are requested, at most once.
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

        return Inertia::render('Stations/Index', [
            'radiusKm' => $radius,
            'showAll' => (bool) ($validated['all'] ?? false),
            'location' => $hasLocation ? ['lat' => (float) $validated['lat'], 'lng' => (float) $validated['lng']] : null,
            'nearby' => fn () => $hasLocation
                ? $this->railway->nearbyStations((float) $validated['lat'], (float) $validated['lng'], $radius, $limit)
                : null,
            'query' => $query,
            'searchResults' => fn () => $search()[0],
            'searchError' => fn () => $search()[1],
            'searchMinLength' => $minLength,
            'searchDebounceMs' => $isMock ? 250 : 400,
        ]);
    }

    public function show(Request $request, string $code): Response
    {
        $tab = BoardType::tryFrom((string) $request->query('tab')) ?? BoardType::Arrivals;
        $boardError = null;

        // Resolved eagerly so provider failures surface as the dashboard's error state.
        try {
            $station = $this->railway->station($code) ?? abort(404);
            $board = $this->railway->stationBoard($station->code, $tab);
        } catch (RailwayDataException $e) {
            if ($e->reason === RailwayDataException::NOT_FOUND) {
                abort(404);
            }
            report($e);
            $station ??= $this->placeholderStation($code);
            $board = [];
            $boardError = $e->getMessage();
        }

        $now = $this->railway->now();

        return Inertia::render('Stations/Show', [
            'station' => $station,
            'tab' => $tab,
            'board' => $board,
            'boardError' => $boardError,
            'today' => $now->toDateString(),
            'updatedAt' => $now->toIso8601String(),
        ]);
    }

    /** Header for a station whose details could not be loaded (only the code is known). */
    private function placeholderStation(string $code): StationDetail
    {
        $code = strtoupper($code);

        return new StationDetail($code, $code, null, null, null, null, null, null, null, null);
    }
}
