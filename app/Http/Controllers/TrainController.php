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
        $live = config('railway.provider') !== 'mock';
        // "All trains" lists the demo timetable; the live provider has no such list.
        $all = (bool) ($validated['all'] ?? false) && ! $live;
        // Live data costs API quota: searched on submit, and never for very short queries.
        $minLength = $live ? (int) config('railway.search_min_length', 2) : 1;

        $results = null;
        $searchError = null;

        try {
            if (mb_strlen($query) >= $minLength || $all) {
                $results = $this->railway->searchTrains($query, $all ? 50 : 10);
            }
        } catch (RailwayDataException $e) {
            $searchError = $e->getMessage();
        }

        return Inertia::render('Trains/Search', [
            'liveData' => $live,
            'searchOnSubmit' => $live,
            'minQueryLength' => $minLength,
            'query' => $query,
            'showAll' => $all,
            'results' => $results,
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
