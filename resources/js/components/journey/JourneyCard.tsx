import { Link } from '@inertiajs/react';
import { BoardStatusPill, DelayPill, PlatformBadge } from '@/components/train/Badges';
import { Icon } from '@/components/ui/Icon';
import { journeyTimes } from '@/lib/journey';
import { cn } from '@/lib/format';
import { urls } from '@/lib/urls';
import type { JourneyOption } from '@/types/railway';

/** One train serving From → To (same card language as the station board's BoardCard). */
export function JourneyCard({ option }: { option: JourneyOption }) {
    const { departure } = option;
    const cancelled = departure.status === 'cancelled';
    const past = departure.phase === 'completed';
    const times = journeyTimes(option);

    return (
        <Link
            href={urls.train(departure.trainNumber)}
            className="block cursor-pointer rounded-2xl border border-outline-variant/30 bg-surface-container-lowest p-3.5 shadow-sm transition-all hover:border-outline-variant active:scale-[0.99]"
        >
            <div className="flex items-start justify-between">
                <div className="flex min-w-0 items-center space-x-2">
                    <span
                        className={cn(
                            'h-2.5 w-2.5 shrink-0 rounded-full ring-4',
                            cancelled || past ? 'bg-outline-variant ring-outline-variant/20' : 'bg-secondary ring-secondary/20',
                        )}
                    />
                    <span className={cn('font-headline-md text-headline-md font-bold tabular-nums', cancelled ? 'text-outline line-through' : 'text-on-surface')}>
                        {departure.trainNumber}
                    </span>
                    <span className={cn('ml-1 truncate font-body-md text-body-md font-semibold', cancelled ? 'text-outline line-through' : 'text-on-surface')}>
                        {departure.trainName}
                    </span>
                </div>
                <div className="flex shrink-0 items-center space-x-1">
                    {times.showDelay && <DelayPill minutes={departure.delayMinutes!} />}
                    <Icon name="chevron_right" className="text-[18px] text-outline" />
                </div>
            </div>

            <dl className="mt-2.5 space-y-1.5 border-t border-surface-container pt-2">
                <TimeRow icon="trip_origin" label={`Departs ${option.boarding.name}`} time={times.departs} extra={times.expectedDeparture} delayed={times.delayed} muted={cancelled} />
                <TimeRow
                    icon="location_on"
                    label={`Arrives ${option.alighting.name}`}
                    time={times.arrives ?? '--'}
                    extra={[times.expectedArrival, times.arrivalDay].filter(Boolean).join(' · ') || null}
                    delayed={times.delayed}
                    muted={cancelled}
                />
            </dl>

            <div className="mt-2 flex items-center justify-end space-x-2">
                <PlatformBadge platform={cancelled ? null : departure.platform} muted={cancelled} />
                <BoardStatusPill status={departure.status} />
            </div>
        </Link>
    );
}

function TimeRow({ icon, label, time, extra, delayed, muted }: { icon: string; label: string; time: string; extra: string | null; delayed: boolean; muted: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="flex min-w-0 items-center gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                <Icon name={icon} className="text-base text-primary" />
                <span className="truncate">{label}</span>
            </dt>
            <dd className="flex shrink-0 items-baseline gap-1.5">
                <span className={cn('font-body-lg text-body-lg font-extrabold tabular-nums', muted ? 'text-outline' : 'text-on-surface')}>{time}</span>
                {extra && <span className={cn('font-body-sm text-body-sm font-medium tabular-nums', delayed ? 'text-tertiary' : 'text-on-surface-variant')}>{extra}</span>}
            </dd>
        </div>
    );
}
