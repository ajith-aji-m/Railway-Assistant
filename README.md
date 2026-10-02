# Railway Assistant

Web prototype of the **Railway Assistant** UI from the Stitch export
(`design/stitch_railway_assistant_ui_design.zip`), built with Laravel 12, React 19,
TypeScript, Inertia v3, SQLite, and Leaflet + OpenStreetMap.

Flow: **Location → Nearest Station → Station Dashboard → Train Details → Live Train Map**,
plus Train Search and Settings. All railway data is **mock data** — no real railway API,
no authentication, no booking.

## Getting started

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate   # if .env is missing
touch database/database.sqlite
php artisan migrate --seed
composer run dev        # Laravel server + Vite (http://127.0.0.1:8000)
```

Geolocation only works on `localhost`/`127.0.0.1` or HTTPS.

```bash
php artisan test        # feature tests (in-memory SQLite)
npm run types           # TypeScript check
npm run build           # production assets
```

## Docker deployment

A single image (`Dockerfile`) builds the Vite assets, installs production PHP dependencies
and serves the app with Apache + PHP 8.4 on port 80. SQLite, logs and cache live in the
`storage` volume, so data survives container rebuilds.

```bash
cp .env.example .env.production
# edit .env.production: APP_KEY, APP_URL=https://your-domain, APP_DEBUG=false,
# LOG_LEVEL=warning, RAILWAY_PROVIDER / API_KEY_RAILWAY as needed
php artisan key:generate --show     # paste the output into APP_KEY

docker compose up -d --build        # http://<server>:8080 (override with APP_PORT)
docker compose logs -f app
```

On every start the container caches config/routes/views, runs migrations and the
(idempotent) mock seeder; set `RUN_MIGRATIONS=false` / `RUN_SEEDERS=false` to skip them.
`.env.production` is read at container start, so `docker compose up -d` applies changes;
`VITE_*` values are baked in at build time (build args in `docker-compose.yml`).

Health check: `GET /api/health` returns JSON with database and cache checks (`200` when
healthy, `503` otherwise) and is used by the Docker `HEALTHCHECK`. It never calls the railway
provider, so probes don't use the RailRadar quota. `GET /up` is Laravel's plain liveness ping.

Put the container behind a TLS-terminating reverse proxy (Caddy, Nginx, Traefik) —
geolocation only works over HTTPS. All proxies are trusted by default; restrict with
`TRUSTED_PROXIES` (comma-separated IPs/CIDRs) if the port is reachable directly.

## Mock railway data

- Network: `database/data/stations.json` (25 stations, real coordinates) and
  `database/data/trains.json` (13 trains). Re-seed with `php artisan migrate:fresh --seed`.
- Live status is **simulated** from the timetable plus per-stop mock delays
  (`App\Railway\Mock\JourneySimulator`): position, speed, next stop, board statuses.
- Demo clock: `RAILWAY_MOCK_CLOCK=08:00` (default) makes the simulated time start at 08:00
  and advance with the minutes of the current real hour, looping hourly, so trains are
  always in motion. Leave it empty to use the real time.
- Scenarios included: running + delayed (12675 Kovai Express), GPS lost / timetable mode
  (12639 Brindavan Express), cancelled (16057 Sapthagiri Express), overnight trains.

## Swapping in a real railway API

Controllers and pages depend only on `App\Railway\Contracts\RailwayProvider`, which returns
the DTOs in `app/Railway/Data` (mirrored in `resources/js/types/railway.ts`). To go live:

1. Implement `RailwayProvider` (e.g. `App\Railway\Http\HttpRailwayProvider`).
2. Register it in `AppServiceProvider` and set `RAILWAY_PROVIDER` in `.env`.

## Map tiles

Standard OpenStreetMap tiles via Leaflet (`resources/js/lib/mapConfig.ts`). Override with
`VITE_MAP_TILE_URL` / `VITE_MAP_ATTRIBUTION`. Respect the
[OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/) — use a
dedicated tile provider before any production traffic.

## Structure

```
app/Railway/            Provider contract, DTOs, enums, mock provider + simulator
app/Http/Controllers/   StationController, TrainController (Inertia pages)
resources/css/app.css   Stitch design tokens (Tailwind v4 @theme)
resources/js/pages/     Stations/Index, Stations/Show, Trains/Search, Trains/Show,
                        Trains/LiveMap, LiveMap/Index, Settings, Error
resources/js/components layout/ ui/ station/ train/ map/
```
