import { Icon } from '@/components/ui/Icon';
import { cn, formatDuration, minutesUntil, timeOf, to12h } from '@/lib/format';
import { delayDisplay, describeLocation, stationLabel, stopBySequence } from '@/lib/live';
import type { TrainDetail } from '@/types/railway';

function CardLabel({ icon, children }: { icon: string; children: string }) {
    return (
        <div className="flex items-center gap-2">
            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Icon name={icon} className="text-[18px]" />
            </div>
            <span className="font-label-sm text-label-sm tracking-wide text-on-surface-variant uppercase">{children}</span>
        </div>
    );
}

/** `staleSince`: ISO time of the data on screen when the latest refresh failed. */
export function LocationSpeedCards({ train, staleSince = null }: { train: TrainDetail; staleSince?: string | null }) {
    const { live } = train;
    const location = describeLocation(train, live);
    const gpsLost = live.gps === 'lost' && live.status === 'running';

    return (
        <div className="grid grid-cols-2 gap-gutter-sm">
            <article className="flex flex-col justify-between rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-3.5 shadow-sm">
                <CardLabel icon="location_on">Current Location</CardLabel>
                <div className="mt-2.5">
                    <h2 className="font-body-lg text-body-lg leading-tight font-bold text-on-surface">{location.title}</h2>
                    <p className="mt-0.5 font-body-sm text-body-sm text-on-surface-variant">{location.detail}</p>
                </div>
            </article>
            <article className="flex flex-col justify-between rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-3.5 shadow-sm">
                <CardLabel icon="speed">Speed</CardLabel>
                <div className="mt-2.5 flex items-baseline gap-1">
                    <span className="font-metric-display text-metric-display font-extrabold text-on-surface tabular-nums">{live.speedKmh ?? '--'}</span>
                    <span className="font-label-sm text-label-sm font-semibold text-on-surface-variant">km/h</span>
                </div>
                {live.status === 'running' && (
                    <p className={cn('mt-0.5 flex items-center gap-1 font-label-sm text-label-sm font-medium', gpsLost || staleSince ? 'text-amber-600' : 'text-secondary')}>
                        <span className={cn('h-1.5 w-1.5 rounded-full', gpsLost || staleSince ? 'bg-amber-500' : 'bg-secondary')} />
                        {staleSince ? `Not updated since ${to12h(timeOf(staleSince))}` : gpsLost ? 'GPS Signal Lost' : 'GPS Tracked'}
                    </p>
                )}
            </article>
        </div>
    );
}

/** `now`: current time of the data source (ISO); countdowns are measured from it. */
export function DelayCard({ train, now }: { train: TrainDetail; now?: string }) {
    const { live } = train;
    const next = stopBySequence(live, live.nextStopSequence);
    const delay = delayDisplay(live);
    const delayed = delay.kind === 'late';
    const scheduledOnly = delay.kind === 'scheduled';
    // Timetable-only trains show the scheduled arrival, never an "expected" one.
    const arrival = scheduledOnly ? next?.scheduledArrival : next?.expectedArrival;

    if (live.status === 'cancelled') return null;

    return (
        <article className="space-y-3 rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <span className="font-label-sm text-label-sm tracking-wide text-on-surface-variant uppercase">Delay Status</span>
                <span
                    className={cn(
                        'rounded-full px-2.5 py-0.5 font-label-md text-label-md font-bold',
                        delayed
                            ? 'bg-error-container text-tertiary'
                            : scheduledOnly
                              ? 'bg-surface-container-high text-on-surface-variant'
                              : 'bg-secondary-container/50 text-on-secondary-container',
                    )}
                >
                    {delay.text}
                </span>
            </div>
            {next && arrival && (
                <div className="grid grid-cols-2 gap-4 border-t border-outline-variant/20 pt-2">
                    <div>
                        <span className="block font-body-sm text-body-sm text-on-surface-variant">Next Station</span>
                        <h3 className="mt-0.5 font-headline-md text-headline-md font-bold text-on-surface">{stationLabel(next)}</h3>
                        {next.platform && <span className="font-label-sm text-label-sm font-semibold text-outline">Platform {next.platform} (Expected)</span>}
                    </div>
                    <div className="text-right">
                        <span className="block font-body-sm text-body-sm text-on-surface-variant">{scheduledOnly ? 'Scheduled Arrival' : 'Expected Arrival'}</span>
                        <div className="mt-0.5 font-headline-md text-headline-md font-bold text-on-surface tabular-nums">{to12h(arrival)}</div>
                        {live.status === 'running' && !scheduledOnly && (
                            <span className={cn('font-body-sm text-body-sm font-semibold', delayed ? 'text-tertiary' : 'text-secondary')}>
                                (in {formatDuration(minutesUntil(now ?? live.updatedAt, arrival))})
                            </span>
                        )}
                    </div>
                </div>
            )}
        </article>
    );
}
