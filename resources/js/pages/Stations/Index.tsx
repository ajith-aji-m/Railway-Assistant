import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { InfoTip } from '@/components/ui/InfoTip';
import { StateCard } from '@/components/ui/StateCard';
import { SearchField } from '@/components/ui/SearchField';
import { DetectingState, LocationErrorState, NoStationsState, PermissionState } from '@/components/station/LocationStates';
import { StationListItem } from '@/components/station/StationListItem';
import { OrDivider, TrainSearchCard, UseLocationCard } from '@/components/station/UseLocationCard';
import { hasLocationPermission, useGeolocation } from '@/hooks/useGeolocation';
import { getLastLocation, setLastLocation } from '@/hooks/useLastLocation';
import { useSettings } from '@/hooks/useSettings';
import { formatDistance } from '@/lib/format';
import { restoreLocation } from '@/lib/location';
import { createSearchScheduler, queryState } from '@/lib/search';
import { urls } from '@/lib/urls';
import type { LatLng, StationSummary } from '@/types/railway';

interface Props {
    radiusKm: number;
    showAll: boolean;
    location: LatLng | null;
    nearby: StationSummary[] | null;
    query: string;
    searchResults: StationSummary[] | null;
    /** Set when the station-search provider failed (safe message). */
    searchError: string | null;
    /** Mock: 1 char / 250ms. RailRadar: 2 chars / 400ms (protects the API quota). */
    searchMinLength: number;
    searchDebounceMs: number;
}

const EXTENDED_RADIUS = 100;

export default function StationsIndex({
    radiusKm,
    showAll,
    location,
    nearby,
    query,
    searchResults,
    searchError,
    searchMinLength,
    searchDebounceMs,
}: Props) {
    const [settings] = useSettings();
    const geo = useGeolocation();
    const [search, setSearch] = useState(query);
    const [searching, setSearching] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    // Coordinates reach the server only for the nearby-station lookup, never with searches.
    const nearbyParams = (position: LatLng, extra: Record<string, string | number | undefined> = {}) => {
        const all = {
            lat: position.lat,
            lng: position.lng,
            radius: radiusKm === EXTENDED_RADIUS ? EXTENDED_RADIUS : undefined, // otherwise the configured default
            all: showAll ? 1 : undefined,
            ...extra,
        };
        return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== undefined)) as Record<string, string | number>;
    };

    const showNearby = (position: LatLng, extra: Record<string, string | number | undefined> = {}) => {
        router.get(urls.stations(), nearbyParams(position, extra), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['location', 'nearby', 'radiusKm', 'showAll'],
        });
    };

    const locateAndSave = async () => {
        const position = await geo.locate();
        if (position) setLastLocation(position);
        return position;
    };

    const detect = async () => {
        const position = await locateAndSave();
        if (position) showNearby(position);
    };

    // Reuse the saved position, or detect silently when permission was already granted — never prompt here.
    useEffect(() => {
        if (location || !settings.useLocation) return;
        void restoreLocation({ saved: getLastLocation, permissionGranted: hasLocationPermission, locate: locateAndSave }).then((position) => {
            if (position) showNearby(position);
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Manual search covers every station; it is never limited by the user's location.
    const runSearch = (q: string) =>
        router.get(urls.stations(), q ? { q } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['query', 'searchResults', 'searchError'],
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    // Always call the latest runSearch.
    const runSearchRef = useRef(runSearch);
    runSearchRef.current = runSearch;

    // Debounced and de-duplicated; queries under the minimum length are not sent.
    const scheduler = useMemo(
        () => createSearchScheduler({ delayMs: searchDebounceMs, minLength: searchMinLength, onSearch: (q) => runSearchRef.current(q) }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [searchDebounceMs, searchMinLength],
    );
    useEffect(() => {
        scheduler.prime(query);
        return () => scheduler.cancel();
    }, [scheduler]); // eslint-disable-line react-hooks/exhaustive-deps

    const onSearchChange = (value: string) => {
        setSearch(value);
        scheduler.update(value);
    };
    const retrySearch = () => {
        scheduler.reset();
        runSearch(search.trim());
    };

    const openManualSearch = () => setSheetOpen(true);
    const searchReady = queryState(search, searchMinLength) === 'ready';
    const activeSearch = searchReady && (searchResults !== null || searchError !== null);
    // Suggestions come only from the user's location — no default/global stations.
    const suggestions = location ? (nearby ?? []) : [];

    let content;
    if (geo.state.status === 'detecting') {
        content = <DetectingState radiusKm={radiusKm} />;
    } else if (geo.state.status === 'denied' || geo.state.status === 'error') {
        content = (
            <LocationErrorState
                denied={geo.state.status === 'denied'}
                timedOut={geo.state.status === 'error' && geo.state.reason === 'timeout'}
                onRetry={detect}
                onSearch={openManualSearch}
            />
        );
    } else if (location && nearby && nearby.length === 0 && !activeSearch) {
        content = (
            <NoStationsState
                radiusKm={radiusKm}
                onSearch={openManualSearch}
                onExtend={radiusKm < EXTENDED_RADIUS ? () => showNearby(location, { radius: EXTENDED_RADIUS }) : undefined}
            />
        );
    } else if (location || activeSearch) {
        const list = activeSearch ? (searchResults ?? []) : (nearby ?? []);
        content = (
            <div className="flex flex-col space-y-space-lg">
                {location && (
                    <>
                        <UseLocationCard onClick={detect} />
                        <OrDivider />
                    </>
                )}
                <SearchField
                    ref={searchRef}
                    value={search}
                    onChange={onSearchChange}
                    loading={searching}
                    placeholder="Search railway station"
                    aria-label="Search railway station"
                />
                <section className="flex items-center justify-between pt-1">
                    <h2 className="font-headline-md text-headline-md font-bold tracking-tight text-on-surface">
                        {activeSearch ? 'Search Results' : 'Nearby Stations'}
                    </h2>
                    {!activeSearch && location && !showAll && (nearby?.length ?? 0) > 0 && (
                        <Link
                            href={urls.stations(nearbyParams(location, { all: 1 }))}
                            only={['nearby', 'showAll']}
                            preserveState
                            preserveScroll
                            replace
                            className="flex items-center font-label-lg text-label-lg font-bold text-primary transition-colors hover:text-on-primary-fixed-variant"
                        >
                            See All
                        </Link>
                    )}
                </section>
                <div className="flex flex-col space-y-2.5">
                    {list.map((station) => (
                        <StationListItem key={station.code} station={station} />
                    ))}
                    {activeSearch && searchError && (
                        <StateCard tone="error" icon="wifi_off" badge="Service Alert" title="Station Search Unavailable" description={searchError}>
                            <Button icon="refresh" onClick={retrySearch}>
                                Try Again
                            </Button>
                        </StateCard>
                    )}
                    {activeSearch && !searchError && list.length === 0 && (
                        <p className="rounded-2xl border border-outline-variant/35 bg-surface-container-lowest p-4 text-center font-body-md text-body-md text-on-surface-variant">
                            No stations match “{search}”.
                        </p>
                    )}
                </div>
                <InfoTip>Tap any railway hub to explore real-time platform allocations, train schedules, and live arrival updates.</InfoTip>
                <TrainSearchCard />
            </div>
        );
    } else {
        content = <PermissionState onAllow={detect} onSearch={openManualSearch} />;
    }

    return (
        <AppShell title="Stations" nav="stations">
            <main className="flex flex-1 flex-col px-margin pt-space-lg">{content}</main>

            <BottomSheet open={sheetOpen} onClose={() => setSheetOpen(false)} title="Search Station">
                <SearchField
                    autoFocus
                    icon="train"
                    value={search}
                    onChange={onSearchChange}
                    loading={searching}
                    placeholder="Enter station name or 3-4 letter code (e.g. SBC)"
                    aria-label="Station name or code"
                    className="bg-surface"
                />
                <div className="space-y-1.5 pt-1">
                    {(activeSearch || suggestions.length > 0) && (
                        <span className="block font-label-sm text-label-sm tracking-wider text-outline uppercase">
                            {activeSearch ? 'Matching Stations' : 'Nearby Stations'}
                        </span>
                    )}
                    {activeSearch && searchError && (
                        <p className="py-2 text-center font-body-sm text-body-sm text-tertiary">
                            <Icon name="wifi_off" className="mr-1 text-base" />
                            {searchError}{' '}
                            <button type="button" onClick={retrySearch} className="font-bold text-primary hover:underline">
                                Try again
                            </button>
                        </p>
                    )}
                    {(activeSearch ? (searchResults ?? []) : suggestions).map((s) => (
                        <Link
                            key={s.code}
                            href={urls.station(s.code)}
                            className="flex w-full items-center justify-between rounded-xl border border-outline-variant/40 p-2.5 text-left transition-colors hover:bg-surface-container"
                        >
                            <div>
                                <p className="font-label-lg text-label-lg font-bold text-on-surface">
                                    {s.name} ({s.code})
                                </p>
                                <p className="font-body-sm text-body-sm text-on-surface-variant">
                                    {[s.city, s.state].filter(Boolean).join(', ') || '—'}
                                    {s.distanceKm !== null ? ` • ${formatDistance(s.distanceKm, settings.distanceUnit)} away` : ''}
                                </p>
                            </div>
                            <span className="rounded bg-surface-container px-2 py-0.5 font-label-sm text-label-sm font-bold text-primary">Select</span>
                        </Link>
                    ))}
                    {!activeSearch && location && nearby !== null && nearby.length === 0 && (
                        <p className="py-4 text-center font-body-sm text-body-sm text-on-surface-variant">
                            <Icon name="location_off" className="mr-1 text-base" />
                            No stations within {radiusKm} km. Search any station by name or code.
                        </p>
                    )}
                    {activeSearch && !searchError && (searchResults ?? []).length === 0 && (
                        <p className="py-4 text-center font-body-sm text-body-sm text-on-surface-variant">
                            <Icon name="search_off" className="mr-1 text-base" />
                            No stations match “{search}”.
                        </p>
                    )}
                </div>
            </BottomSheet>
        </AppShell>
    );
}
