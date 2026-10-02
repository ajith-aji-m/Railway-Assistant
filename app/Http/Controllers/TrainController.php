<?php

namespace App\Http\Controllers;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Exceptions\RailwayDataException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrainController extends Controller
{
    public function __construct(private readonly RailwayProvider $railway) {}

    public function search(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'all' => ['nullable', 'boolean'],
        ]);
        $query = trim($validated['q'] ?? '');
        $all = (bool) ($validated['all'] ?? false);
        $mode = config('railway.search_mode.'.config('railway.provider'), 'trains');
        $minLength = (int) config('railway.search_min_length', 2);

        $results = null;
        $stationResults = null;
        $searchError = null;

        try {
            if ($mode === 'stations') {
                // Short queries are not searched (no upstream request).
                $stationResults = mb_strlen($query) >= $minLength ? $this->railway->searchStations($query) : null;
            } elseif ($query !== '' || $all) {
                $results = $this->railway->searchTrains($query, $all ? 50 : 10);
            }
        } catch (RailwayDataException $e) {
            $searchError = $e->getMessage();
        }

        return Inertia::render('Trains/Search', [
            'searchMode' => $mode,
            'liveData' => config('railway.provider') !== 'mock',
            'minQueryLength' => $minLength,
            'query' => $query,
            'showAll' => $all && $mode === 'trains',
            'results' => $results,
            'stationResults' => $stationResults,
            'searchError' => $searchError,
        ]);
    }

    public function show(string $number): Response
    {
        return Inertia::render('Trains/Show', [
            'train' => $this->railway->train($number) ?? abort(404),
            // Current time of the data source, so "in N min" is measured from now —
            // not from the provider's (possibly older) last-updated time.
            'now' => $this->railway->now()->toIso8601String(),
        ]);
    }

    public function map(string $number): Response
    {
        $train = $this->railway->train($number) ?? abort(404);

        return Inertia::render('Trains/LiveMap', [
            'train' => $train,
            // Polled as its own prop so a refresh only re-sends live data; it comes
            // from the same provider call as `train` (one upstream request per visit).
            'live' => $train->live,
        ]);
    }
}
