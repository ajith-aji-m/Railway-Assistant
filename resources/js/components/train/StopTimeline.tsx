import { Icon } from '@/components/ui/Icon';
import { cn, to12h } from '@/lib/format';
import type { StopStatus } from '@/types/railway';

function delayText(stop: StopStatus) {
    if (stop.delayMinutes === null) return <span className="block font-label-sm text-label-sm font-semibold text-outline">—</span>;
    return stop.delayMinutes > 0 ? (
        <span className="block font-label-sm text-label-sm font-bold text-tertiary">+{stop.delayMinutes}m late</span>
    ) : (
        <span className="block font-label-sm text-label-sm font-semibold text-secondary">On time</span>
    );
}

function subtitle(stop: StopStatus, isLast: boolean) {
    if (stop.state === 'departed') return isLast ? `Arrived ${to12h(stop.expectedArrival)}` : `Departed ${to12h(stop.expectedDeparture)}`;
    if (stop.state === 'current') return `At platform · Departs ${to12h(stop.expectedDeparture)}`;
    if (stop.state === 'next') return `Expected ${to12h(stop.expectedArrival)}`;
    return `Scheduled ${to12h(stop.scheduledArrival ?? stop.scheduledDeparture)}`;
}

/** Route timeline: departed (green check), current/next (pulsing blue), upcoming (outline). */
export function StopTimeline({ stops, cancelled = false }: { stops: StopStatus[]; cancelled?: boolean }) {
    const lastSequence = stops[stops.length - 1]?.sequence;

    return (
        <div className="relative space-y-5 pl-7">
            <div className="absolute top-3 bottom-3 left-2.5 w-[2px] -translate-x-1/2 bg-outline-variant/40" />
            {stops.map((stop) => {
                const time = to12h(stop.scheduledArrival ?? stop.scheduledDeparture);
                const name = `${stop.station.name} (${stop.station.code})`;
                const highlight = stop.state === 'next' || stop.state === 'current';

                if (highlight && !cancelled) {
                    return (
                        <div key={stop.sequence} className="relative -mx-3 flex items-start justify-between rounded-lg border border-primary/20 bg-primary/5 p-3">
                            <div className="absolute top-4 -left-4 z-10 flex items-center justify-center">
                                <div className="flex h-5 w-5 items-center justify-center rounded-full bg-primary ring-4 ring-surface-container-lowest">
                                    <span className="h-2 w-2 rounded-full bg-on-primary" />
                                </div>
                                <div className="absolute h-8 w-8 animate-pulse-ring rounded-full bg-primary/30" />
                            </div>
                            <div className="pl-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h4 className="font-body-lg text-body-lg font-bold text-primary">{name}</h4>
                                    <span className="rounded bg-primary-fixed px-1.5 py-0.5 font-label-sm text-label-sm font-semibold text-on-primary-fixed">
                                        {stop.state === 'current' ? 'At Station' : 'Next Stop'}
                                    </span>
                                </div>
                                <p className="mt-0.5 font-body-sm text-body-sm text-on-surface-variant">{subtitle(stop, stop.sequence === lastSequence)}</p>
                            </div>
                            <div className="shrink-0 text-right">
                                <span className="font-body-lg text-body-lg font-bold text-on-surface tabular-nums">{time}</span>
                                {delayText(stop)}
                            </div>
                        </div>
                    );
                }

                const departed = stop.state === 'departed';
                return (
                    <div key={stop.sequence} className={cn('relative flex items-start justify-between', !departed && 'opacity-80')}>
                        {departed ? (
                            <div className="absolute top-1 -left-7 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-secondary text-on-secondary ring-4 ring-surface-container-lowest">
                                <Icon name="check" className="text-[13px] font-bold" />
                            </div>
                        ) : (
                            <div className="absolute top-1 -left-7 z-10 flex h-5 w-5 items-center justify-center rounded-full border-2 border-outline-variant bg-surface-container-lowest ring-4 ring-surface-container-lowest">
                                <span className="h-2 w-2 rounded-full bg-outline-variant" />
                            </div>
                        )}
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h4 className={cn('font-body-lg text-body-lg text-on-surface', departed ? 'font-bold' : 'font-medium')}>{name}</h4>
                                {stop.platform && (
                                    <span className="rounded bg-surface-container-high px-1.5 py-0.5 font-label-sm text-label-sm font-semibold text-on-surface-variant">
                                        Plat {stop.platform}
                                    </span>
                                )}
                            </div>
                            <p className="mt-0.5 font-body-sm text-body-sm text-on-surface-variant">
                                {cancelled ? 'Cancelled' : subtitle(stop, stop.sequence === lastSequence)}
                            </p>
                        </div>
                        <div className="shrink-0 text-right">
                            <span className={cn('font-body-lg text-body-lg text-on-surface tabular-nums', departed ? 'font-semibold' : 'font-semibold')}>{time}</span>
                            {!cancelled && delayText(stop)}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/** The slice shown in "Schedule (Next Stops)": last departed stop + the next three. */
export function nextStops(stops: StopStatus[], count = 4): StopStatus[] {
    const pivot = stops.findIndex((s) => s.state === 'current' || s.state === 'next');
    if (pivot === -1) {
        const allDeparted = stops.every((s) => s.state === 'departed');
        return allDeparted ? stops.slice(-count) : stops.slice(0, count);
    }
    const start = Math.max(0, pivot - 1);
    return stops.slice(start, start + count);
}
