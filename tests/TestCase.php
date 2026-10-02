<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Fakes RailRadar's station timetable (GET /v1/stations/{code}/trains), which the
     * full-day station board loads next to the live board. Empty unless trains are given.
     */
    protected function fakeStationTimetable(array $trains = [], string $code = '*'): void
    {
        Http::fake(["https://api.railradar.in/v1/stations/{$code}/trains*" => Http::response([
            'success' => true,
            'data' => ['station' => [], 'count' => count($trains), 'includeIntermediate' => false, 'trains' => $trains],
            'meta' => [],
        ])]);
    }
}
