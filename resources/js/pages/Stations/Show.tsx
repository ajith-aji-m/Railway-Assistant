import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { BoardCard, BoardCardSkeleton } from '@/components/station/BoardCard';
import { BoardEmpty, BoardError } from '@/components/station/BoardStates';
import { StationHero } from '@/components/station/StationHero';
import { StationInfoStrip } from '@/components/station/StationInfoStrip';
import { Icon } from '@/components/ui/Icon';
import { SegmentedTabs } from '@/components/ui/SegmentedTabs';
import { getLastLocation } from '@/hooks/useLastLocation';
import { useLiveReload } from '@/hooks/useLiveReload';
import { useRecentSearches } from '@/hooks/useRecentSearches';
import { cn, formatDate } from '@/lib/format';
import { distanceKm } from '@/lib/geo';
import { goBack } from '@/lib/navigation';
import { urls } from '@/lib/urls';
import type { SharedProps } from '@/types/inertia';
import type { BoardEntry, BoardType, StationDetail } from '@/types/railway';

interface Props {
    station: StationDetail;
    tab: BoardType;
    board: BoardEntry[];
    /** Set when the data provider failed (rate limit, outage, …); shows the error state. */
    boardError: string | null;
    today: string;
    updatedAt: string;
}

const LIVE_PROPS = ['board', 'boardError', 'updatedAt'];

export default function StationShow({ station, tab, board, boardError, today }: Props) {
    // Server-configured: 60s for mock data, off by default for RailRadar (monthly quota).
    const refreshSeconds = usePage<SharedProps>().props.liveRefresh.station;
    const live = useLiveReload(LIVE_PROPS, refreshSeconds > 0 ? refreshSeconds * 1000 : undefined);
    const [switching, setSwitching] = useState<BoardType | null>(null);
    const lastLocation = getLastLocation();
    const distance =
        lastLocation && station.lat !== null && station.lng !== null ? distanceKm(lastLocation, { lat: station.lat, lng: station.lng }) : null;
    const { add: addRecent } = useRecentSearches();

    useEffect(() => addRecent({ kind: 'station', code: station.code, label: station.name }), [station.code]); // eslint-disable-line react-hooks/exhaustive-deps

    const activeTab = switching ?? tab;
    const loading = switching !== null || live.loading;

    const switchTab = (next: BoardType) => {
        router.get(urls.station(station.code, next), {}, {
            only: ['board', 'boardError', 'tab', 'updatedAt'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...live.callbacks,
            onStart: () => setSwitching(next),
            onFinish: () => setSwitching(null),
        });
    };

    return (
        <AppShell title={`${station.name} (${station.code})`} header={null} nav="stations">
            <StationHero station={station} distanceKm={distance} onBack={() => goBack(urls.stations())} />
            <StationInfoStrip station={station} />

            <nav aria-label="Station schedule view" className="sticky top-0 z-20 w-full border-b border-outline-variant/30 bg-surface-container-lowest px-margin pt-3 pb-1">
                <SegmentedTabs
                    value={activeTab}
                    onChange={switchTab}
                    tabs={[
                        { value: 'arrivals', label: 'Arrivals', icon: 'flight_land' },
                        { value: 'departures', label: 'Departures', icon: 'flight_takeoff' },
                    ]}
                />
                <div className="flex items-center justify-between py-2.5 text-on-surface-variant">
                    <div className="flex items-center space-x-2">
                        <Icon name="calendar_today" className="text-[18px] text-primary" />
                        <span className="font-label-md text-label-md font-bold tracking-normal text-on-surface">Today, {formatDate(today)}</span>
                    </div>
                    <div className="flex items-center space-x-1 text-on-surface-variant">
                        <span className="rounded-full bg-surface-container px-2 py-0.5 font-label-sm text-label-sm font-semibold text-primary">Live</span>
                        <button
                            type="button"
                            aria-label="Refresh Schedule"
                            onClick={live.reload}
                            className="rounded-full p-1 transition-transform hover:bg-surface-container active:scale-95"
                        >
                            <Icon name="sync" className={cn('text-[18px]', live.loading && 'animate-spin')} />
                        </button>
                    </div>
                </div>
            </nav>

            <main className="flex-1 space-y-3 px-margin py-3">
                {live.error || boardError ? (
                    <BoardError onRetry={live.reload} />
                ) : loading && switching ? (
                    <>
                        <BoardCardSkeleton />
                        <BoardCardSkeleton />
                        <BoardCardSkeleton dim />
                    </>
                ) : board.length === 0 ? (
                    <BoardEmpty type={tab} stationName={station.name} onRefresh={live.reload} />
                ) : (
                    board.map((entry) => <BoardCard key={`${entry.trainNumber}-${entry.scheduledTime}`} entry={entry} />)
                )}
            </main>
        </AppShell>
    );
}
