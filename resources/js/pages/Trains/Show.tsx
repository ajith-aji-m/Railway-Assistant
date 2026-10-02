import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { Amenities } from '@/components/train/Amenities';
import { DelayCard, LocationSpeedCards } from '@/components/train/LiveCards';
import { nextStops, StopTimeline } from '@/components/train/StopTimeline';
import { TrainHeader, TrainIdentity } from '@/components/train/TrainHeader';
import { Icon } from '@/components/ui/Icon';
import { UnderlineTabs } from '@/components/ui/UnderlineTabs';
import { useTrainFlags } from '@/hooks/useFavourites';
import { rememberTrain } from '@/hooks/useLastTrain';
import { useLiveReload } from '@/hooks/useLiveReload';
import { useRecentSearches } from '@/hooks/useRecentSearches';
import { cn } from '@/lib/format';
import { goBack } from '@/lib/navigation';
import { urls } from '@/lib/urls';
import type { SharedProps } from '@/types/inertia';
import type { TrainDetail } from '@/types/railway';

const LIVE_PROPS = ['train'];

export default function TrainShow({ train }: { train: TrainDetail }) {
    const [tab, setTab] = useState<'overview' | 'route'>('overview');
    const { alert, toggleAlert } = useTrainFlags(train.number);
    // Server-configured: 30s for mock data, 5 min by default for RailRadar (monthly quota).
    const refreshSeconds = usePage<SharedProps>().props.liveRefresh.train;
    useLiveReload(LIVE_PROPS, refreshSeconds > 0 ? refreshSeconds * 1000 : undefined);

    const { add: addRecent } = useRecentSearches();

    useEffect(() => {
        rememberTrain(train.number);
        addRecent({ kind: 'train', code: train.number, label: train.name });
    }, [train.number]); // eslint-disable-line react-hooks/exhaustive-deps

    const cancelled = train.live.status === 'cancelled';
    const stops = tab === 'overview' ? nextStops(train.live.stops) : train.live.stops;

    return (
        <AppShell title={`${train.number} ${train.name}`} header={null} nav={false} className="pb-24">
            <TrainHeader train={train} onBack={() => goBack(urls.trainSearch())} />

            <section className="border-b border-outline-variant/30 bg-surface-container-lowest px-margin pt-4 pb-3">
                <TrainIdentity train={train} />
                <UnderlineTabs
                    active={tab}
                    onChange={(key) => setTab(key as 'overview' | 'route')}
                    tabs={[
                        { key: 'overview', label: 'Overview' },
                        { key: 'route', label: 'Route' },
                        { key: 'map', label: 'Live Map', href: urls.trainMap(train.number) },
                    ]}
                />
            </section>

            <div className="space-y-space-md px-margin pt-space-md pb-space-md">
                {tab === 'overview' && (
                    <>
                        <LocationSpeedCards train={train} />
                        <DelayCard train={train} />
                    </>
                )}

                <section className="rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-4 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Icon name="route" className="text-[20px] text-primary" />
                            <h2 className="font-headline-md text-headline-md font-bold text-on-surface">
                                {tab === 'overview' ? 'Schedule (Next Stops)' : `Full Route · ${train.live.stops.at(-1)?.distanceKm ?? 0} km`}
                            </h2>
                        </div>
                        {tab === 'overview' && stops.length < train.live.stops.length && (
                            <button type="button" onClick={() => setTab('route')} className="font-label-md text-label-md font-bold text-primary hover:underline">
                                View All
                            </button>
                        )}
                    </div>
                    <StopTimeline stops={stops} cancelled={cancelled} />
                </section>

                <Amenities train={train} />
            </div>

            <div className="fixed bottom-0 left-1/2 z-30 flex w-full max-w-md -translate-x-1/2 gap-3 border-t border-outline-variant/20 bg-surface-container-lowest/90 p-margin pb-[max(env(safe-area-inset-bottom),1rem)] shadow-md backdrop-blur-md">
                <button
                    type="button"
                    onClick={toggleAlert}
                    aria-pressed={alert}
                    aria-label={alert ? 'Arrival alert on' : 'Set arrival alert'}
                    title={alert ? 'Arrival alert on' : 'Set arrival alert'}
                    className={cn(
                        'flex h-12 w-12 items-center justify-center rounded-xl border border-outline-variant/40 transition-colors hover:bg-surface-container-high active:scale-95',
                        alert ? 'text-primary' : 'text-on-surface-variant',
                    )}
                >
                    <Icon name="notifications_active" fill={alert} className="text-[22px]" />
                </button>
                <Link
                    href={urls.trainMap(train.number)}
                    className="flex h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-primary-container font-label-lg text-label-lg font-bold text-on-primary-container shadow-sm transition-all hover:bg-primary active:scale-95"
                >
                    <Icon name="map" className="text-[20px]" />
                    <span>View Live Map</span>
                </Link>
            </div>
        </AppShell>
    );
}
