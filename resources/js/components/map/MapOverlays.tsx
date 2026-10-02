import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/Icon';
import { runningLabel } from '@/components/train/Badges';
import { cn, formatDateTime, minutesUntil, to12h } from '@/lib/format';
import { describeLocation, mapLocationDetail, stopBySequence } from '@/lib/live';
import { urls } from '@/lib/urls';
import type { LiveStatus, TrainDetail } from '@/types/railway';

const runningColor = { running: 'text-secondary', scheduled: 'text-primary', completed: 'text-outline', cancelled: 'text-tertiary' } as const;
const runningDot = { running: 'bg-secondary', scheduled: 'bg-primary', completed: 'bg-outline', cancelled: 'bg-tertiary' } as const;

export function MapHeaderCard({ train, live, onBack, onShare }: { train: TrainDetail; live: LiveStatus; onBack: () => void; onShare: () => void }) {
    return (
        <div className="flex w-full items-center justify-between rounded-xl border border-outline-variant/50 bg-surface-container-lowest/95 p-space-md shadow-md backdrop-blur-md">
            <div className="flex min-w-0 items-center space-x-space-sm">
                <button
                    type="button"
                    aria-label="Go Back"
                    onClick={onBack}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-on-surface transition-colors hover:bg-surface-container active:scale-95"
                >
                    <Icon name="arrow_back" className="text-[20px]" />
                </button>
                <div className="min-w-0">
                    <h1 className="truncate font-headline-md text-headline-md leading-tight font-bold text-on-surface">
                        {train.number} – {train.name}
                    </h1>
                    <div className="mt-0.5 flex items-center space-x-2">
                        <span className={cn('inline-flex items-center font-label-sm text-[11px] font-bold', runningColor[live.status])}>
                            <span className={cn('mr-1 inline-block h-2 w-2 rounded-full', runningDot[live.status])} />
                            {runningLabel(live.status)}
                        </span>
                        {live.status !== 'cancelled' && (
                            <>
                                <span className="text-[11px] text-outline">•</span>
                                <span
                                    className={cn(
                                        'inline-flex items-center rounded px-1.5 font-label-sm text-[11px] font-bold',
                                        live.delayMinutes > 0 ? 'bg-error-container text-tertiary' : 'bg-secondary/10 text-secondary',
                                    )}
                                >
                                    {live.delayMinutes > 0 ? `+${live.delayMinutes} min` : 'On time'}
                                </span>
                            </>
                        )}
                    </div>
                </div>
            </div>
            <button
                type="button"
                aria-label="Share status"
                onClick={onShare}
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-on-surface-variant transition-colors hover:bg-surface-container active:scale-95"
            >
                <Icon name="share" className="text-[20px]" />
            </button>
        </div>
    );
}

export function GpsLostBanner() {
    return (
        <div className="flex items-center justify-between rounded-xl border border-amber-300/80 bg-amber-50/95 px-3 py-2 shadow-sm backdrop-blur-md">
            <div className="flex items-center space-x-2">
                <span className="relative flex h-2.5 w-2.5">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                    <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
                </span>
                <div className="flex flex-col">
                    <div className="flex items-center space-x-1.5">
                        <span className="font-label-md text-label-md font-bold text-amber-900">Train GPS Signal Lost</span>
                        <span className="rounded bg-amber-200/80 px-1.5 font-label-sm text-[10px] text-amber-950">TIMETABLE MODE</span>
                    </div>
                    <span className="font-label-sm text-[11px] leading-tight text-amber-800">Showing Scheduled Stations &amp; Estimated Track Segment</span>
                </div>
            </div>
            <Icon name="location_disabled" className="text-lg text-amber-700" />
        </div>
    );
}

function Fab({ label, onClick, disabled, active, children, className }: { label: string; onClick?: () => void; disabled?: boolean; active?: boolean; children: ReactNode; className?: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            aria-pressed={active}
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'flex h-11 w-11 items-center justify-center rounded-xl shadow-lg transition-transform active:scale-95',
                disabled
                    ? 'cursor-not-allowed border border-outline-variant/40 bg-surface-container/90 text-outline-variant shadow-none'
                    : active
                      ? 'bg-primary text-on-primary ring-2 ring-primary/30'
                      : 'border border-outline-variant/60 bg-surface-container-lowest hover:bg-surface-container',
                className,
            )}
        >
            {children}
        </button>
    );
}

export function MapControls({
    gpsLost,
    labelsOn,
    onRecenter,
    onZoomIn,
    onZoomOut,
    onToggleLabels,
}: {
    gpsLost: boolean;
    labelsOn: boolean;
    onRecenter: () => void;
    onZoomIn: () => void;
    onZoomOut: () => void;
    onToggleLabels: () => void;
}) {
    return (
        <aside className="flex flex-col space-y-2">
            <Fab label={gpsLost ? 'Train GPS unavailable' : 'Re-center on train'} onClick={onRecenter} disabled={gpsLost} className={gpsLost ? '' : 'text-primary'}>
                <Icon name={gpsLost ? 'gps_off' : 'my_location'} fill={!gpsLost} className="text-[22px]" />
            </Fab>
            <div className="flex flex-col divide-y divide-outline-variant/40 overflow-hidden rounded-xl border border-outline-variant/60 bg-surface-container-lowest shadow-lg">
                <button type="button" aria-label="Zoom in" onClick={onZoomIn} className="flex h-10 w-11 items-center justify-center text-on-surface hover:bg-surface-container active:scale-95">
                    <Icon name="add" className="text-[20px]" />
                </button>
                <button type="button" aria-label="Zoom out" onClick={onZoomOut} className="flex h-10 w-11 items-center justify-center text-on-surface hover:bg-surface-container active:scale-95">
                    <Icon name="remove" className="text-[20px]" />
                </button>
            </div>
            <Fab label={labelsOn ? 'Hide station labels' : 'Show station labels'} onClick={onToggleLabels} active={labelsOn} className={labelsOn ? '' : 'text-on-surface-variant'}>
                <Icon name="layers" className="text-[20px]" />
            </Fab>
        </aside>
    );
}

/** Bottom floating telemetry card (GPS active). */
export function TelemetryCard({ train, live, refreshing, onRefresh }: { train: TrainDetail; live: LiveStatus; refreshing: boolean; onRefresh: () => void }) {
    const location = describeLocation(train, live);
    const last = stopBySequence(live, live.lastStopSequence);
    const next = stopBySequence(live, live.nextStopSequence);
    const target = next ?? last;

    const detail = mapLocationDetail(train, live);

    return (
        <div className="flex w-full flex-col space-y-space-sm rounded-2xl border border-outline-variant/60 bg-surface-container-lowest/95 p-space-md shadow-xl backdrop-blur-md">
            <div className="mx-auto -mt-1 mb-1 h-1 w-10 rounded-full bg-outline-variant" />
            <div className="flex items-center justify-between">
                <div className="flex min-w-0 items-start space-x-space-sm">
                    <div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Icon name="near_me" className="text-[22px]" />
                    </div>
                    <div className="min-w-0">
                        <span className="block font-label-sm text-[11px] font-bold tracking-wider text-outline uppercase">Current Location</span>
                        <h2 className="font-headline-md text-headline-md font-bold text-on-surface">{location.title}</h2>
                        <p className="font-body-sm text-body-sm text-on-surface-variant">{detail}</p>
                    </div>
                </div>
                {live.status === 'running' && (
                    <div className="flex shrink-0 flex-col items-end pl-2">
                        <div className="flex items-center rounded-lg border border-outline-variant/40 bg-surface-container-low px-2.5 py-1">
                            <Icon name="speed" className="mr-1 text-[16px] text-primary" />
                            <span className="font-headline-md text-headline-md font-extrabold text-on-surface tabular-nums">{live.speedKmh ?? '--'}</span>
                            <span className="ml-0.5 font-label-sm text-[10px] font-bold text-outline">km/h</span>
                        </div>
                        <span className="mt-1 flex items-center font-label-sm text-[10px] font-bold text-secondary">
                            <span className="mr-1 h-1.5 w-1.5 rounded-full bg-secondary" />
                            GPS Active
                        </span>
                    </div>
                )}
            </div>
            <div className="my-1 h-[1px] w-full bg-outline-variant/30" />
            <div className="flex items-center justify-between pt-0.5">
                <div className="flex items-center space-x-1 font-label-sm text-[11px] text-on-surface-variant">
                    <Icon name="schedule" className="text-[14px] text-outline" />
                    <span>Updated: {formatDateTime(live.updatedAt)}</span>
                </div>
                <div className="flex items-center space-x-1">
                    <button type="button" aria-label="Refresh telemetry" onClick={onRefresh} className="flex items-center rounded-lg p-1 text-primary hover:bg-surface-container active:scale-95">
                        <Icon name="sync" className={cn('text-[18px]', refreshing && 'animate-spin')} />
                    </button>
                    {target && (
                        <Link
                            href={urls.station(target.station.code)}
                            className="flex items-center space-x-0.5 pl-1 font-label-sm text-label-sm font-bold whitespace-nowrap text-primary hover:underline active:scale-95"
                        >
                            <span>Station Details</span>
                            <Icon name="chevron_right" className="text-[16px]" />
                        </Link>
                    )}
                </div>
            </div>
        </div>
    );
}

/** Bottom card when GPS is lost: position estimated from the timetable. */
export function EstimatedCard({ live, onShare }: { live: LiveStatus; onShare: () => void }) {
    const last = stopBySequence(live, live.lastStopSequence);
    const next = stopBySequence(live, live.nextStopSequence);
    if (!last || !next) return null;

    const lastTime = last.expectedDeparture ?? last.expectedArrival;
    const ago = lastTime ? -minutesUntil(live.updatedAt, lastTime) : null;

    return (
        <div className="space-y-3 rounded-2xl border border-surface-container bg-surface-container-lowest/95 p-4 shadow-xl backdrop-blur-md">
            <div className="mx-auto h-1 w-10 rounded-full bg-outline-variant/70" />
            <div className="space-y-1">
                <div className="flex items-center justify-between">
                    <span className="rounded bg-amber-100/90 px-2 py-0.5 font-label-sm text-label-sm font-bold tracking-wider text-amber-700 uppercase">Estimated Location</span>
                    {ago !== null && ago >= 0 && (
                        <div className="flex items-center space-x-1 font-label-sm text-label-sm text-on-surface-variant">
                            <Icon name="schedule" className="text-sm" />
                            <span>
                                {ago}m ago ({to12h(lastTime)})
                            </span>
                        </div>
                    )}
                </div>
                <h2 className="font-headline-md text-headline-md font-bold text-on-surface">
                    {live.atStation ? `At ${last.station.name}` : `Between ${last.station.name} & ${next.station.name}`}
                </h2>
                <p className="font-body-sm text-body-sm text-outline">
                    Train progress calculated via timetable schedule. Next milestone expected at {to12h(next.expectedArrival)}.
                </p>
            </div>
            <div className="flex items-center justify-between rounded-xl border border-surface-container bg-surface-container-low p-3">
                <div className="space-y-0.5">
                    <div className="font-label-sm text-label-sm text-outline">PASSED</div>
                    <div className="text-sm font-bold text-on-surface">{last.station.name}</div>
                    <div className="font-label-sm text-label-sm font-bold text-secondary">
                        Dept {to12h(last.expectedDeparture)}
                        {(last.delayMinutes ?? 0) > 0 && ` (+${last.delayMinutes}m)`}
                    </div>
                </div>
                <div className="flex flex-col items-center px-2">
                    <span className="mb-1 rounded-full bg-amber-100/80 px-2 py-0.5 font-label-sm text-[11px] font-bold whitespace-nowrap text-amber-700">
                        ~{Math.round(live.distanceToNextKm ?? 0)} km to go
                    </span>
                    <div className="w-16 border-t-2 border-dashed border-outline-variant" />
                </div>
                <div className="space-y-0.5 text-right">
                    <div className="font-label-sm text-label-sm text-outline">NEXT STOP</div>
                    <div className="text-sm font-bold text-on-surface">{next.station.name}</div>
                    <div className="font-label-sm text-label-sm font-bold text-outline">
                        Exp {to12h(next.expectedArrival)}
                        {next.platform && ` (Plat ${next.platform})`}
                    </div>
                </div>
            </div>
            <div className="grid grid-cols-2 gap-2 pt-1">
                <Link
                    href={urls.station(next.station.code)}
                    className="flex items-center justify-center space-x-1.5 rounded-xl bg-surface-container px-3 py-2.5 font-label-md text-label-md text-on-surface transition hover:bg-surface-container-high active:scale-[0.98]"
                >
                    <Icon name="alt_route" className="text-base" />
                    <span>Station Details</span>
                </Link>
                <button
                    type="button"
                    onClick={onShare}
                    className="flex items-center justify-center space-x-1.5 rounded-xl bg-primary-container px-3 py-2.5 font-label-md text-label-md text-on-primary-container shadow-sm transition hover:bg-primary active:scale-[0.98]"
                >
                    <Icon name="share" className="text-base" />
                    <span>Share Status</span>
                </button>
            </div>
        </div>
    );
}

export function MapLoadingOverlay() {
    return (
        <div className="absolute inset-0 z-[5] flex flex-col justify-center bg-slate-100 p-4">
            <div className="relative flex w-full flex-col items-center justify-center py-6">
                <div className="skeleton-shimmer mb-8 h-1 w-full -rotate-12 rounded-full" />
                <div className="skeleton-shimmer h-1.5 w-4/5 -rotate-6 rounded-full" />
                <div className="mt-8 flex w-full justify-between px-4">
                    <div className="skeleton-shimmer h-5 w-20 rounded-lg" />
                    <div className="skeleton-shimmer h-5 w-16 rounded-lg" />
                    <div className="skeleton-shimmer h-5 w-24 rounded-lg" />
                </div>
            </div>
        </div>
    );
}

export function MapErrorOverlay({ onRetry, timetableHref }: { onRetry: () => void; timetableHref: string }) {
    return (
        <div className="absolute inset-0 z-[30] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
            <div className="w-full max-w-xs space-y-3 rounded-2xl border border-error-container bg-surface-container-lowest p-4 text-center shadow-xl">
                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-error-container text-tertiary">
                    <Icon name="cloud_off" className="text-2xl" />
                </div>
                <div className="space-y-1">
                    <h3 className="font-headline-md text-headline-md font-bold text-on-surface">Unable to Load Live Map</h3>
                    <p className="font-body-sm text-body-sm text-on-surface-variant">Check your network connection to stream live map tiles.</p>
                </div>
                <div className="space-y-2 pt-1">
                    <button
                        type="button"
                        onClick={onRetry}
                        className="flex w-full items-center justify-center space-x-1.5 rounded-xl bg-primary-container px-4 py-2.5 font-label-lg text-on-primary-container shadow-sm transition hover:bg-primary active:scale-95"
                    >
                        <Icon name="refresh" className="text-base" />
                        <span>Retry Connection</span>
                    </button>
                    <Link
                        href={timetableHref}
                        className="block w-full rounded-xl bg-surface-container-high px-4 py-2 font-label-md text-on-surface transition hover:bg-surface-container active:scale-95"
                    >
                        View Text Timetable
                    </Link>
                </div>
            </div>
        </div>
    );
}

