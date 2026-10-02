<?php

namespace App\Railway\RailRadar;

use App\Railway\Exceptions\RailwayDataException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the RailRadar API (https://railradar.in/docs).
 *
 * - Auth: `Authorization: Bearer <key>` (key from config, never logged).
 * - Responses use the envelope { success, data, meta{traceId,…} } or
 *   { success:false, error{code,message}, meta }.
 */
class RailRadarClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $cacheSeconds,
        private readonly int $searchCacheSeconds = 86400,
        /** How long a request waits for another request's in-flight refresh (null = HTTP timeout + 2 s). */
        private readonly ?int $lockWaitSeconds = null,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('services.railradar');

        return new self(
            apiKey: $config['key'] ?? null,
            baseUrl: rtrim($config['base_url'] ?? 'https://api.railradar.in', '/'),
            timeout: (int) ($config['timeout'] ?? 10),
            cacheSeconds: (int) ($config['cache_seconds'] ?? 60),
            searchCacheSeconds: (int) ($config['search_cache_seconds'] ?? 86400),
        );
    }

    /**
     * GET /v1/trains/{number}/live — live running status for the current run.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function liveTrain(string $number, bool $fresh = false): array
    {
        if (! preg_match('/^\d{5}$/', $number)) {
            throw RailwayDataException::notFound("Train {$number}");
        }

        return $this->get("/v1/trains/{$number}/live", ['includeCoordinates' => 'true'], "Train {$number}", $fresh, lockKey: "railradar:live:lock:{$number}");
    }

    /**
     * GET /v1/stations/{code}/live — live arrival/departure board.
     * Documented `hours` values: 2, 4, 6, 8 (hours ahead).
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function stationLive(string $code, int $hours = 8, bool $fresh = false): array
    {
        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{1,10}$/', $code)) {
            throw RailwayDataException::notFound("Station {$code}");
        }

        if (! in_array($hours, [2, 4, 6, 8], true)) {
            $hours = 8;
        }

        return $this->get("/v1/stations/{$code}/live", ['hours' => (string) $hours], "Station {$code}", $fresh);
    }

    /**
     * GET /v1/stations/{code}/trains — the station's timetable (all scheduled trains
     * halting, originating or terminating there). Static data: cached like lookups.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function stationTrains(string $code): array
    {
        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{1,10}$/', $code)) {
            throw RailwayDataException::notFound("Station {$code}");
        }

        return $this->get("/v1/stations/{$code}/trains", [], "Station {$code}", false, $this->searchCacheSeconds);
    }

    /**
     * GET /v1/lookup/search/trains — train autocomplete by number or name substring.
     * Documented parameters: `q` (required) and `limit` (5, 10, 20, 50).
     *
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    public function searchTrains(string $query, int $limit = 10): array
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        $limit = in_array($limit, [5, 10, 20, 50], true) ? $limit : 10;

        return $this->get('/v1/lookup/search/trains', ['q' => $query, 'limit' => (string) $limit], 'Train search', false, $this->searchCacheSeconds);
    }

    /**
     * GET /v1/lookup/search/stations — station autocomplete.
     * Documented parameters: `q` (required) and `limit` (5, 10, 20, 50).
     *
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    public function searchStations(string $query, int $limit = 10): array
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        $limit = in_array($limit, [5, 10, 20, 50], true) ? $limit : 10;

        return $this->get('/v1/lookup/search/stations', ['q' => $query, 'limit' => (string) $limit], 'Station search', false, $this->searchCacheSeconds);
    }

    /**
     * @param  array<string, string>  $query
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function get(string $path, array $query, string $resource, bool $fresh, ?int $ttl = null, ?string $lockKey = null): array
    {
        $ttl ??= $this->cacheSeconds;

        if (blank($this->apiKey)) {
            throw RailwayDataException::notConfigured();
        }

        $cacheKey = 'railradar:'.sha1($path.'?'.http_build_query($query));

        if (! $fresh && $ttl > 0 && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        if ($lockKey === null || $fresh || $ttl <= 0) {
            return $this->fetch($path, $query, $resource, $cacheKey, $ttl);
        }

        // Concurrent cache misses for the same resource: one request refreshes it, the
        // others wait and reuse its cached response. The lock outlives the HTTP timeout
        // (so it cannot expire mid-request) but stays short, so a crashed holder never
        // blocks later refreshes for long.
        try {
            return Cache::lock($lockKey, $this->timeout + 5)->block(
                $this->lockWaitSeconds ?? $this->timeout + 2,
                fn () => Cache::get($cacheKey) ?? $this->fetch($path, $query, $resource, $cacheKey, $ttl),
            );
        } catch (LockTimeoutException) {
            // Still refreshing elsewhere: never start a second upstream request.
            return Cache::get($cacheKey) ?? throw RailwayDataException::timeout();
        }
    }

    /**
     * @param  array<string, string>  $query
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function fetch(string $path, array $query, string $resource, string $cacheKey, int $ttl): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->connectTimeout(min(5, $this->timeout))
                ->timeout($this->timeout)
                ->get($path, $query);
        } catch (ConnectionException) {
            // Deliberately not chaining the original exception (it carries request details).
            $this->logFailure($path, RailwayDataException::TIMEOUT);
            throw RailwayDataException::timeout();
        }

        $payload = $this->decode($response, $path, $resource);

        if ($ttl > 0) {
            Cache::put($cacheKey, $payload, $ttl);
        }

        return $payload;
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function decode(Response $response, string $path, string $resource): array
    {
        $json = $response->json();
        $traceId = is_array($json) ? ($json['meta']['traceId'] ?? null) : null;
        $status = $response->status();

        $error = match (true) {
            $status === 401, $status === 403 => RailwayDataException::unauthorized($status, $traceId),
            $status === 404 => RailwayDataException::notFound($resource, $traceId),
            $status === 429 => RailwayDataException::rateLimited(
                is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null,
                $traceId,
            ),
            $status >= 500 => RailwayDataException::unavailable($status, $traceId),
            $status >= 400 => RailwayDataException::invalidResponse("HTTP {$status}", $traceId),
            ! is_array($json) => RailwayDataException::invalidResponse('body is not JSON'),
            ($json['success'] ?? null) !== true => RailwayDataException::invalidResponse('success flag is not true', $traceId),
            ! is_array($json['data'] ?? null) => RailwayDataException::invalidResponse('missing data object', $traceId),
            default => null,
        };

        if ($error) {
            $this->logFailure($path, $error->reason, $status, $traceId);
            throw $error;
        }

        return ['data' => $json['data'], 'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : []];
    }

    private function logFailure(string $path, string $reason, ?int $status = null, ?string $traceId = null): void
    {
        Log::warning('RailRadar request failed', array_filter([
            'path' => $path,
            'reason' => $reason,
            'status' => $status,
            'traceId' => $traceId,
        ]));
    }
}
