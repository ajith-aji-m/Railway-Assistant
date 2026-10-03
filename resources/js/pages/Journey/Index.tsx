import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { JourneyCard } from '@/components/journey/JourneyCard';
import { AppShell } from '@/components/layout/AppShell';
import { TitleBar } from '@/components/layout/TopAppBar';
import { BoardCardSkeleton } from '@/components/station/BoardCard';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { SearchField } from '@/components/ui/SearchField';
import { StateCard } from '@/components/ui/StateCard';
import { hasLocationPermission, useGeolocation } from '@/hooks/useGeolocation';
import { getLastLocation, setLastLocation } from '@/hooks/useLastLocation';
import { useSettings } from '@/hooks/useSettings';
import { cn, formatDate, formatDistance } from '@/lib/format';
import { canFindTrains, groupJourneys, NO_TRAINS, pickerPanel, suggestedFrom, swapStations, type JourneyField } from '@/lib/journey';
import { restoreLocation } from '@/lib/location';
import { createSearchScheduler } from '@/lib/search';
import { urls } from '@/lib/urls';
import type { JourneyOption, LatLng, StationSummary } from '@/types/railway';

interface Props {
    location: LatLng | null;
    /** From suggestions: nearest first, from the local station directory. */
    nearby: StationSummary[] | null;
    query: string;
    searchResults: StationSummary[] | null;
    searchError: string | null;
    /** Mock: 1 char / 250ms. RailRadar: 2 chars / 400ms (same as the Stations screen). */
    searchMinLength: number;
    searchDebounceMs: number;
    from: StationSummary | null;
    to: StationSummary | null;
    /** null until both stations are chosen and searched. */
    journeys: JourneyOption[] | null;
    journeyError: string | null;
    today: string;
}

const PHASE_DOT = { completed: 'bg-outline-variant', running: 'bg-secondary', upcoming: 'bg-primary' } as const;

const pairKey = (from: StationSummary | null, to: StationSummary | null) => (from && to ? `${from.code}>${to.code}` : null);

export default function JourneyIndex(props: Props) {
    const { location, nearby, query, searchResults, searchError, searchMinLength, searchDebounceMs, journeys, journeyError, today } = props;
    const [settings] = useSettings();
    const geo = useGeolocation();

    const [from, setFrom] = useState<StationSummary | null>(props.from);
    const [to, setTo] = useState<StationSummary | null>(props.to);
    // Set once the user picks (or swaps) From, so the nearest-station suggestion never overrides it.
    const [fromChosen, setFromChosen] = useState(props.from !== null);
    const [picker, setPicker] = useState<JourneyField | null>(null);
    const [text, setText] = useState('');
    const [searching, setSearching] = useState(false);
    const [loading, setLoading] = useState(false);
    // The From → To pair the shown results belong to (results from a URL count as searched).
    const [searched, setSearched] = useState<string | null>(journeys !== null || journeyError !== null ? pairKey(props.from, props.to) : null);

    const selection = { from, to };
    const params = (extra: Record<string, string | number | undefined> = {}) => ({ from: from?.code, to: to?.code, ...extra });

    // From: reuse the saved position (or detect silently when permission was already granted) — never prompt here.
    useEffect(() => {
        if (location || !settings.useLocation) return;
        void restoreLocation({
            saved: getLastLocation,
            permissionGranted: hasLocationPermission,
            locate: async () => {
                const position = await geo.locate();
                if (position) setLastLocation(position);
                return position;
            },
        }).then((position) => {
            if (!position) return; // no location: From is searched like To
            router.get(urls.journey(params({ lat: position.lat, lng: position.lng })), {}, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['location', 'nearby'],
            });
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Nearest station as the default From (nearby is ordered by distance).
    const suggestion = fromChosen ? null : suggestedFrom(null, location ? nearby : null);
    const shownFrom = from ?? suggestion;

    // Station search for the open picker: the same debounce / minimum length / quota rules as the Stations screen.
    const runSearch = (q: string) =>
        router.get(urls.journey(params({ q: q || undefined })), {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['query', 'searchResults', 'searchError'],
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    const runSearchRef = useRef(runSearch);
    runSearchRef.current = runSearch;
    const scheduler = useMemo(
        () => createSearchScheduler({ delayMs: searchDebounceMs, minLength: searchMinLength, onSearch: (q) => runSearchRef.current(q) }),
        [searchDebounceMs, searchMinLength],
    );
    useEffect(() => {
        scheduler.prime(query);
        return () => scheduler.cancel();
    }, [scheduler]); // eslint-disable-line react-hooks/exhaustive-deps

    const onTextChange = (value: string) => {
        setText(value);
        scheduler.update(value);
    };
    const openPicker = (field: JourneyField) => {
        setText('');
        scheduler.update('');
        setPicker(field);
    };
    const closePicker = () => {
        scheduler.cancel();
        setPicker(null);
    };
    const choose = (station: StationSummary) => {
        if (picker === 'from') {
            setFrom(station);
            setFromChosen(true);
        } else {
            setTo(station);
        }
        closePicker();
    };
    const swap = () => {
        const next = swapStations({ from: shownFrom, to });
        setFrom(next.from);
        setTo(next.to);
        setFromChosen(true);
    };
    const retrySearch = () => {
        scheduler.reset();
        runSearch(text.trim());
    };

    const current = { from: shownFrom, to };
    const ready = canFindTrains(current);
    const findTrains = () => {
        if (!ready) return;
        const key = pairKey(shownFrom, to);
        router.get(urls.journey({ from: shownFrom!.code, to: to!.code }), {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['journeys', 'journeyError'],
            onStart: () => setLoading(true),
            onSuccess: () => setSearched(key),
            onFinish: () => setLoading(false),
        });
    };

    // Results are shown only for the pair currently selected.
    const showResults = searched !== null && searched === pairKey(shownFrom, to);
    const panel = picker
        ? pickerPanel(picker, { text, minLength: searchMinLength, query, results: searchResults, error: searchError, nearby, location })
        : null;

    return (
        <AppShell title="Journey Search" nav="stations" header={<TitleBar title="From → To" backHref={urls.stations()} />}>
            <main className="flex-1 space-y-space-lg px-margin pt-space-lg">
                <section className="space-y-1">
                    <span className="font-label-sm text-label-sm font-bold tracking-wider text-primary uppercase">Journey Search</span>
                    <h2 className="font-headline-xl text-headline-xl tracking-tight text-on-surface">Where are you going?</h2>
                    <p className="font-body-sm text-body-sm text-on-surface-variant">Choose your starting station and destination to see today's trains between them.</p>
                </section>

                <div className="relative rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-3.5 shadow-sm">
                    <StationField
                        label="From"
                        icon="trip_origin"
                        station={shownFrom}
                        hint={!fromChosen && suggestion ? `Nearest station${suggestion.distanceKm !== null ? ` · ${formatDistance(suggestion.distanceKm, settings.distanceUnit)} away` : ''}` : null}
                        placeholder="Choose starting station"
                        onOpen={() => openPicker('from')}
                    />
                    <div className="my-2 flex items-center gap-2">
                        <div className="grow border-t border-outline-variant/40" />
                        <button
                            type="button"
                            aria-label="Swap From and To"
                            onClick={swap}
                            disabled={!shownFrom && !to}
                            className="flex h-9 w-9 items-center justify-center rounded-full border border-outline-variant/60 bg-surface-container text-primary transition-colors hover:bg-surface-container-high active:scale-95 disabled:text-outline"
                        >
                            <Icon name="swap_vert" className="text-[20px]" />
                        </button>
                    </div>
                    <StationField label="To" icon="location_on" station={to} hint={null} placeholder="Where do you want to go?" onOpen={() => openPicker('to')} />
                </div>

                {shownFrom && to && shownFrom.code === to.code && (
                    <p className="font-body-sm text-body-sm text-tertiary">From and To must be different stations.</p>
                )}

                <Button icon={loading ? 'progress_activity' : 'search'} disabled={!ready || loading} onClick={findTrains}>
                    Find Trains
                </Button>

                {loading && !showResults && (
                    <section className="space-y-3">
                        <BoardCardSkeleton />
                        <BoardCardSkeleton dim />
                    </section>
                )}

                {showResults && journeyError && (
                    <StateCard tone="error" icon="wifi_off" badge="Service Alert" title="Journey Search Unavailable" description={journeyError}>
                        <Button icon="refresh" onClick={findTrains}>
                            Try Again
                        </Button>
                    </StateCard>
                )}

                {showResults && !journeyError && journeys !== null && journeys.length === 0 && (
                    <StateCard
                        icon="search_off"
                        title={NO_TRAINS}
                        description={`No train today calls at ${shownFrom!.name} and then at ${to!.name}.`}
                    />
                )}

                {showResults && !journeyError && journeys !== null && journeys.length > 0 && (
                    <div className={cn('space-y-space-md transition-opacity', loading && 'opacity-60')}>
                        <div className="flex items-center justify-between">
                            <h2 className="font-headline-md text-headline-md text-on-surface">
                                {shownFrom!.name} → {to!.name}
                            </h2>
                            <span className="rounded-full bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">{formatDate(today)}</span>
                        </div>
                        {groupJourneys(journeys).map((g) => (
                            <section key={g.phase} aria-label={`${g.label} trains`} className="space-y-3">
                                <h3 className="flex items-center gap-2 px-1 pt-1 font-label-sm text-label-sm tracking-wider text-outline uppercase">
                                    <span className={cn('h-2 w-2 rounded-full', PHASE_DOT[g.phase])} />
                                    {g.label} · {g.options.length}
                                </h3>
                                {g.options.map((option) => (
                                    <JourneyCard key={`${option.departure.trainNumber}-${option.departure.scheduledTime}`} option={option} />
                                ))}
                            </section>
                        ))}
                    </div>
                )}
            </main>

            <BottomSheet open={picker !== null} onClose={closePicker} title={picker === 'from' ? 'From Station' : 'To Station'} icon={picker === 'from' ? 'trip_origin' : 'location_on'}>
                <SearchField
                    autoFocus
                    value={text}
                    onChange={onTextChange}
                    loading={searching}
                    placeholder={picker === 'from' ? 'Search starting station' : 'Search destination station'}
                    aria-label={picker === 'from' ? 'From station' : 'To station'}
                    className="bg-surface"
                />
                <div className="space-y-1.5 pt-1">
                    {panel && (panel.kind === 'nearby' || panel.kind === 'results') && (
                        <>
                            <span className="block font-label-sm text-label-sm tracking-wider text-outline uppercase">
                                {panel.kind === 'nearby' ? 'Nearby Stations' : 'Matching Stations'}
                            </span>
                            {panel.stations.map((s) => (
                                <button
                                    key={s.code}
                                    type="button"
                                    onClick={() => choose(s)}
                                    className="flex w-full items-center justify-between rounded-xl border border-outline-variant/40 p-2.5 text-left transition-colors hover:bg-surface-container"
                                >
                                    <div>
                                        <p className="font-label-lg text-label-lg font-bold text-on-surface">
                                            {s.name} ({s.code})
                                        </p>
                                        <p className="font-body-sm text-body-sm text-on-surface-variant">
                                            {s.distanceKm !== null
                                                ? `${formatDistance(s.distanceKm, settings.distanceUnit)} away`
                                                : [s.city, s.state].filter(Boolean).join(', ') || '—'}
                                        </p>
                                    </div>
                                    <span className="rounded bg-surface-container px-2 py-0.5 font-label-sm text-label-sm font-bold text-primary">Select</span>
                                </button>
                            ))}
                        </>
                    )}
                    {panel?.kind === 'hint' && (
                        <p className="py-4 text-center font-body-sm text-body-sm text-on-surface-variant">
                            <Icon name="search" className="mr-1 text-base" />
                            {panel.message}
                        </p>
                    )}
                    {panel?.kind === 'searching' && <p className="py-4 text-center font-body-sm text-body-sm text-on-surface-variant">Searching…</p>}
                    {panel?.kind === 'empty' && (
                        <p className="py-4 text-center font-body-sm text-body-sm text-on-surface-variant">
                            <Icon name="search_off" className="mr-1 text-base" />
                            {panel.message}
                        </p>
                    )}
                    {panel?.kind === 'error' && (
                        <p className="py-2 text-center font-body-sm text-body-sm text-tertiary">
                            <Icon name="wifi_off" className="mr-1 text-base" />
                            {panel.message}{' '}
                            <button type="button" onClick={retrySearch} className="font-bold text-primary hover:underline">
                                Try again
                            </button>
                        </p>
                    )}
                </div>
            </BottomSheet>
        </AppShell>
    );
}

function StationField({
    label,
    icon,
    station,
    hint,
    placeholder,
    onOpen,
}: {
    label: string;
    icon: string;
    station: StationSummary | null;
    hint: string | null;
    placeholder: string;
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            aria-label={`${label}: ${station ? `${station.name} (${station.code})` : placeholder}`}
            className="group flex w-full items-center gap-3 rounded-xl p-1 text-left transition-colors hover:bg-surface-container-low"
        >
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-container-low text-primary">
                <Icon name={icon} className="text-[22px]" />
            </div>
            <div className="flex min-w-0 flex-col">
                <span className="font-label-sm text-label-sm tracking-wider text-outline uppercase">{label}</span>
                {station ? (
                    <span className="flex items-center gap-1.5">
                        <span className="truncate font-body-lg text-body-lg font-bold text-on-surface group-hover:text-primary">{station.name}</span>
                        <span className="rounded bg-surface-container-high px-1.5 py-0.5 font-label-sm text-label-sm font-bold text-on-surface-variant">({station.code})</span>
                    </span>
                ) : (
                    <span className="font-body-lg text-body-lg text-outline">{placeholder}</span>
                )}
                {hint && <span className="font-body-sm text-body-sm text-on-surface-variant">{hint}</span>}
            </div>
            <Icon name="chevron_right" className="ml-auto text-[20px] text-outline-variant group-hover:text-primary" />
        </button>
    );
}
