import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { StationListItem, StationListSkeleton } from '@/components/station/StationListItem';
import { TrainResultCard, TrainResultSkeleton } from '@/components/train/TrainResultCard';
import { Icon } from '@/components/ui/Icon';
import { useRecentSearches } from '@/hooks/useRecentSearches';
import { cn } from '@/lib/format';
import { createSearchScheduler, normalizeQuery, queryState } from '@/lib/search';
import { urls } from '@/lib/urls';
import type { StationSummary, TrainSummary } from '@/types/railway';

interface Props {
    /** "trains" (mock timetable) or "stations" (RailRadar station search). */
    searchMode: 'trains' | 'stations';
    minQueryLength: number;
    liveData: boolean;
    query: string;
    showAll: boolean;
    results: TrainSummary[] | null;
    stationResults: StationSummary[] | null;
    /** Set when the data provider failed; shows the existing error state. */
    searchError: string | null;
}

// Station search hits the RailRadar API: wait longer and require a few characters.
const DEBOUNCE_MS = { trains: 250, stations: 400 } as const;

function SectionTitle({ icon, iconClass, title, aside }: { icon: string; iconClass: string; title: string; aside?: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between">
            <div className="flex items-center gap-1.5">
                <Icon name={icon} className={cn('text-base', iconClass)} />
                <h2 className="font-headline-md text-headline-md text-on-surface">{title}</h2>
            </div>
            {aside}
        </div>
    );
}

export default function TrainSearch({ searchMode, minQueryLength, liveData, query, showAll, results, stationResults, searchError }: Props) {
    const stations = searchMode === 'stations';
    const minLength = stations ? minQueryLength : 1;
    const [value, setValue] = useState(query);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const recent = useRecentSearches();

    const runSearch = (q: string, all = false) => {
        scheduler.prime(q);
        router.get(urls.trainSearch(q ? { q } : all ? { all: 1 } : undefined), {}, {
            only: ['query', 'results', 'stationResults', 'searchError', 'showAll'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onSuccess: () => setError(false),
            onNetworkError: () => (setError(true), false),
            onHttpException: () => (setError(true), false),
            onFinish: () => setLoading(false),
        });
    };

    // Debounced, de-duplicated; queries below the minimum length are never sent.
    const scheduler = useMemo(
        () => createSearchScheduler({ delayMs: DEBOUNCE_MS[searchMode], minLength, onSearch: (q) => runSearch(q) }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [searchMode, minLength],
    );
    useEffect(() => {
        scheduler.prime(query);
        return () => scheduler.cancel();
    }, [scheduler]); // eslint-disable-line react-hooks/exhaustive-deps

    const onChange = (next: string) => {
        setValue(next);
        scheduler.update(next);
    };

    const retry = () => {
        scheduler.reset();
        runSearch(normalizeQuery(value), showAll);
    };

    const state = queryState(value, minLength);
    const hasQuery = state === 'ready' || showAll;
    const failed = error || searchError !== null;
    const pending = stations ? stationResults === null : results === null;

    return (
        <AppShell title="Train Search" nav="search">
            <main className="flex-1 space-y-6 px-margin pt-4">
                <section className="space-y-1">
                    <div className="flex items-center justify-between">
                        <span className="font-label-sm text-label-sm font-bold tracking-wider text-primary uppercase">Live Transit Directory</span>
                        <span className="inline-flex items-center gap-1 rounded-full bg-surface-container-low px-2 py-0.5 font-label-md text-label-md text-secondary">
                            <span className="h-2 w-2 animate-pulse rounded-full bg-secondary" />
                            {liveData ? 'Live Data' : 'Demo Data'}
                        </span>
                    </div>
                    <h1 className="font-headline-xl text-headline-xl tracking-tight text-on-surface">Train Search</h1>
                    <p className="font-body-sm text-body-sm text-on-surface-variant">Query live running status, platforms, and routes across all railway zones.</p>
                </section>

                <div
                    className={cn(
                        'relative flex items-center rounded-xl bg-surface-container-lowest px-3.5 py-2.5 shadow-sm transition-all',
                        value ? 'border-2 border-primary ring-2 ring-primary/10' : 'border border-outline-variant focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20',
                    )}
                >
                    <Icon name={loading ? 'progress_activity' : 'search'} className={cn('mr-2 text-xl', value ? 'text-primary' : 'text-outline', loading && 'animate-spin')} />
                    <input
                        type="search"
                        autoFocus
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder={stations ? 'Search station name or code' : 'Search train number or station name'}
                        aria-label={stations ? 'Search station name or code' : 'Search train number or station name'}
                        className="w-full border-none bg-transparent p-0 font-body-md text-body-md font-semibold text-on-surface placeholder:font-normal placeholder:text-outline focus:ring-0 focus:outline-none [&::-webkit-search-cancel-button]:hidden"
                    />
                    {value && (
                        <button
                            type="button"
                            aria-label="Clear input"
                            onClick={() => onChange('')}
                            className="flex items-center justify-center rounded-full bg-surface-container p-1 text-on-surface-variant transition-colors hover:bg-error-container hover:text-tertiary active:scale-95"
                        >
                            <Icon name="close" className="text-sm font-bold" />
                        </button>
                    )}
                </div>

                {!hasQuery && (
                    <section className="space-y-2.5">
                        <SectionTitle
                            icon="history"
                            iconClass="text-primary"
                            title="Recent Searches"
                            aside={
                                recent.items.length > 0 && (
                                    <button type="button" onClick={recent.clear} className="font-label-md text-label-md text-primary hover:underline">
                                        Clear
                                    </button>
                                )
                            }
                        />
                        {stations && state === 'short' && (
                            <p className="font-body-sm text-body-sm text-on-surface-variant">Type at least {minQueryLength} characters to search stations.</p>
                        )}
                        {recent.items.length > 0 ? (
                            <div className="flex flex-wrap gap-2">
                                {recent.items.map((item) => (
                                    <Link
                                        key={`${item.kind}-${item.code}`}
                                        href={item.kind === 'train' ? urls.train(item.code) : urls.station(item.code)}
                                        className="inline-flex items-center gap-1.5 rounded-full border border-outline-variant bg-surface-container-lowest px-3 py-1.5 font-label-md text-label-md text-on-surface shadow-sm transition-colors hover:border-primary hover:bg-surface-container-low active:scale-95"
                                    >
                                        <Icon name={item.kind === 'train' ? 'train' : 'near_me'} className={cn('text-sm', item.kind === 'train' ? 'text-primary' : 'text-secondary')} />
                                        <span>
                                            {item.code} {item.label}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <p className="font-body-sm text-body-sm text-on-surface-variant">
                                Trains and stations you open will appear here.{' '}
                                {!stations && (
                                    <button type="button" onClick={() => runSearch('', true)} className="font-bold text-primary hover:underline">
                                        View all trains
                                    </button>
                                )}
                            </p>
                        )}
                    </section>
                )}

                {hasQuery && failed && (
                    <div className="relative flex flex-col items-center justify-center overflow-hidden rounded-2xl border border-error-container bg-surface-container-lowest p-5 text-center shadow-sm">
                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-error-container text-tertiary">
                            <Icon name="wifi_off" className="text-2xl" />
                        </div>
                        <h3 className="font-headline-md text-headline-md text-on-surface">Search Service Unavailable</h3>
                        <p className="mt-1 max-w-xs font-body-sm text-body-sm leading-relaxed text-on-surface-variant">
                            Unable to query live railway database. Please check your internet connection.
                        </p>
                        <div className="mt-4 flex items-center gap-2">
                            <button
                                type="button"
                                onClick={retry}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-tertiary px-4 py-2 font-label-md text-label-md font-bold text-on-tertiary shadow-sm transition-all hover:bg-tertiary-container active:scale-95"
                            >
                                <Icon name="refresh" className="text-sm" />
                                <span>Try Again</span>
                            </button>
                        </div>
                    </div>
                )}

                {hasQuery && !failed && loading && pending && (
                    <section className="space-y-2">
                        <SectionTitle icon="progress_activity" iconClass="animate-spin text-primary" title="Searching…" />
                        {stations ? <StationListSkeleton count={2} /> : <TrainResultSkeleton />}
                    </section>
                )}

                {stations && hasQuery && !failed && stationResults !== null && stationResults.length > 0 && (
                    <section className="space-y-2">
                        <SectionTitle
                            icon="check_circle"
                            iconClass="text-secondary"
                            title="Search Results"
                            aside={
                                <span className="rounded-full bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                                    {stationResults.length} {stationResults.length === 1 ? 'match' : 'matches'}
                                </span>
                            }
                        />
                        <div className={cn('flex flex-col space-y-2.5 transition-opacity', loading && 'opacity-60')}>
                            {stationResults.map((station) => (
                                <StationListItem key={station.code} station={station} />
                            ))}
                        </div>
                    </section>
                )}

                {stations && hasQuery && !failed && stationResults !== null && stationResults.length === 0 && (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-outline-variant bg-surface-container-lowest p-6 text-center shadow-sm">
                        <div className="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
                            <Icon name="search" className="text-3xl" />
                        </div>
                        <h3 className="font-headline-md text-headline-md text-on-surface">No Stations Found</h3>
                        <p className="mt-1.5 max-w-xs font-body-sm text-body-sm leading-relaxed text-on-surface-variant">
                            We couldn't find any stations matching <span className="font-semibold text-on-surface">"{normalizeQuery(value)}"</span>. Please check
                            the station name or code.
                        </p>
                    </div>
                )}

                {!stations && hasQuery && !failed && results !== null && results.length > 0 && (
                    <section className="space-y-2">
                        <SectionTitle
                            icon="check_circle"
                            iconClass="text-secondary"
                            title={showAll && !value.trim() ? 'All Trains' : 'Search Results'}
                            aside={
                                <span className="rounded-full bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                                    {results.length} {results.length === 1 ? 'match' : 'matches'}
                                </span>
                            }
                        />
                        <div className={cn('space-y-3 transition-opacity', loading && 'opacity-60')}>
                            {results.map((t) => (
                                <TrainResultCard key={t.number} train={t} />
                            ))}
                        </div>
                    </section>
                )}

                {!stations && hasQuery && !failed && results !== null && results.length === 0 && (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-outline-variant bg-surface-container-lowest p-6 text-center shadow-sm">
                        <div className="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
                            <Icon name="search" className="text-3xl" />
                        </div>
                        <h3 className="font-headline-md text-headline-md text-on-surface">No Trains Found</h3>
                        <p className="mt-1.5 max-w-xs font-body-sm text-body-sm leading-relaxed text-on-surface-variant">
                            We couldn't find any trains matching <span className="font-semibold text-on-surface">"{value.trim()}"</span>. Please verify the train number
                            or search by station name.
                        </p>
                        <button
                            type="button"
                            onClick={() => {
                                setValue('');
                                scheduler.cancel();
                                runSearch('', true);
                            }}
                            className="mt-4 inline-flex items-center justify-center rounded-xl bg-primary px-5 py-2.5 font-label-lg text-label-lg text-on-primary shadow-sm transition-colors transition-transform hover:bg-primary-container active:scale-95"
                        >
                            View All Trains
                        </button>
                    </div>
                )}
            </main>
        </AppShell>
    );
}
