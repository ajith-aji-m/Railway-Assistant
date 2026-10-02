<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
            ],
            // Data-source label for users ("live" or "demo"); the provider itself is
            // an environment setting (RAILWAY_PROVIDER) and is not exposed or switchable.
            'dataSource' => config('railway.provider') === 'mock' ? 'demo' : 'live',
            // Per-screen polling intervals in seconds (0 = manual refresh only).
            'liveRefresh' => array_map('intval', (array) config('railway.auto_refresh_seconds.'.config('railway.provider'), [])) + ['station' => 0, 'train' => 0, 'map' => 0],
        ];
    }
}
