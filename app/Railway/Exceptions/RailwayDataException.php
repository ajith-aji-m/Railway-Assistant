<?php

namespace App\Railway\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Application-level error from a railway data provider. Messages are safe to
 * show and log: they never contain credentials or raw upstream payloads.
 */
final class RailwayDataException extends RuntimeException implements HttpExceptionInterface
{
    public const NOT_CONFIGURED = 'not_configured';
    public const UNAUTHORIZED = 'unauthorized';
    public const NOT_FOUND = 'not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const UNAVAILABLE = 'unavailable';
    public const TIMEOUT = 'timeout';
    public const INVALID_RESPONSE = 'invalid_response';
    public const NOT_SUPPORTED = 'not_supported';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $upstreamStatus = null,
        public readonly ?string $traceId = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'Railway data provider is not configured.');
    }

    public static function unauthorized(?int $status, ?string $traceId): self
    {
        return new self(self::UNAUTHORIZED, 'Railway data provider rejected the API credentials.', $status, $traceId);
    }

    public static function notFound(string $what, ?string $traceId = null): self
    {
        return new self(self::NOT_FOUND, "{$what} was not found.", 404, $traceId);
    }

    public static function rateLimited(?int $retryAfter, ?string $traceId): self
    {
        return new self(self::RATE_LIMITED, 'Railway data provider rate limit reached. Please try again later.', 429, $traceId, $retryAfter);
    }

    public static function unavailable(?int $status, ?string $traceId = null): self
    {
        return new self(self::UNAVAILABLE, 'Railway data provider is temporarily unavailable.', $status, $traceId);
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, 'Railway data provider did not respond in time.');
    }

    public static function invalidResponse(string $detail, ?string $traceId = null): self
    {
        return new self(self::INVALID_RESPONSE, "Railway data provider returned an invalid response ({$detail}).", null, $traceId);
    }

    public static function notSupported(string $feature): self
    {
        return new self(self::NOT_SUPPORTED, "{$feature} is not available from this railway data provider yet.");
    }

    public function getStatusCode(): int
    {
        return $this->reason === self::NOT_FOUND ? 404 : 503;
    }

    public function getHeaders(): array
    {
        return $this->retryAfterSeconds ? ['Retry-After' => (string) $this->retryAfterSeconds] : [];
    }
}
