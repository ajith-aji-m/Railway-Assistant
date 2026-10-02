<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Railway\Exceptions\RailwayDataException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In Docker the app sits behind a reverse proxy that terminates TLS; trust its
        // X-Forwarded-* headers so generated URLs use https (needed for geolocation).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Railway provider failures (rate limit, outage, …) are expected conditions:
        // log a safe one-line warning instead of a full stack trace.
        $exceptions->dontReport(RailwayDataException::class);

        // Render 404s, railway provider failures, and 5xx outside debug mode with
        // the app's own error screen.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $providerError = $e instanceof RailwayDataException;
            $handled = $status === 404 || $providerError || ($status >= 500 && ! app()->hasDebugModeEnabled());

            if (! $handled || $request->expectsJson()) {
                return $response;
            }

            // A failed background refresh (partial reload) must not replace the page:
            // answer with a plain error so the client keeps the data it has.
            if ($providerError && $request->hasHeader('X-Inertia-Partial-Data')) {
                return response()->json(['message' => $e->getMessage()], $status, $e->getHeaders());
            }

            return Inertia::render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status)
                ->withHeaders($providerError ? $e->getHeaders() : []);
        });
    })->create();
