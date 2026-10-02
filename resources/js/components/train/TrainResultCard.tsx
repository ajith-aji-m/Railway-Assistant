import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/Icon';
import { cn, to12h } from '@/lib/format';
import { summaryStatus } from '@/lib/live';
import { urls } from '@/lib/urls';
import type { TrainSummary } from '@/types/railway';

export function TrainResultCard({ train, onOpen }: { train: TrainSummary; onOpen?: () => void }) {
    const { pill, state } = summaryStatus(train);
    const cancelled = train.status === 'cancelled';
    const accent = cancelled || !pill ? 'bg-outline' : pill.tone === 'late' ? 'bg-tertiary' : 'bg-secondary';

    return (
        <article className="group relative block overflow-hidden rounded-2xl border border-outline-variant bg-surface-container-lowest p-4 shadow-sm transition-shadow hover:shadow-md">
            {/* Whole card opens Train Details; the Live Map link sits above it. */}
            <Link href={urls.train(train.number)} onClick={onOpen} aria-label={`${train.number} ${train.name} – Train Details`} className="absolute inset-0 z-0" />
            <div className={cn('absolute top-0 bottom-0 left-0 w-1.5', accent)} />
            <div className="flex items-start justify-between gap-2">
                <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-container text-primary transition-colors group-hover:bg-primary group-hover:text-on-primary">
                        <Icon name="directions_transit" className="text-xl" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="font-label-lg text-label-lg font-bold text-primary tabular-nums">{train.number}</span>
                            {train.originPlatform && (
                                <>
                                    <span className="font-body-sm text-body-sm text-outline">•</span>
                                    <span className="rounded bg-surface-container px-1.5 py-0.5 font-label-sm text-label-sm font-bold tracking-wider text-on-surface-variant uppercase">
                                        Platform {train.originPlatform}
                                    </span>
                                </>
                            )}
                        </div>
                        <h3 className="mt-0.5 font-headline-md text-headline-md text-on-surface">{train.name}</h3>
                        <p className="mt-0.5 flex items-center gap-1 font-body-sm text-body-sm text-on-surface-variant">
                            <span>{train.from.name}</span>
                            <Icon name="arrow_forward" className="text-xs text-outline" />
                            <span>{train.to.name}</span>
                        </p>
                    </div>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-2">
                    {pill && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-label-sm text-label-sm font-bold',
                                pill.tone === 'muted' ? 'bg-surface-container-high text-outline' : pill.tone === 'late' ? 'bg-error-container text-on-error-container' : 'bg-secondary-container/40 text-on-secondary-container',
                            )}
                        >
                            <span className={cn('h-1.5 w-1.5 rounded-full', pill.tone === 'muted' ? 'bg-outline' : pill.tone === 'late' ? 'bg-tertiary' : 'animate-ping bg-secondary')} />
                            {pill.label}
                        </span>
                    )}
                    {state && <span className={cn('font-label-sm text-label-sm font-bold', train.status === 'running' ? 'text-secondary' : 'text-outline')}>{state}</span>}
                </div>
            </div>
            <div className="mt-3.5 flex items-center justify-between border-t border-outline-variant/60 pt-3 font-body-sm text-body-sm">
                {train.departs || train.arrives ? (
                    <div className="flex items-center gap-4">
                        <div>
                            <span className="block font-label-sm text-label-sm text-outline">Departs</span>
                            <span className="font-label-md text-label-md font-bold text-on-surface tabular-nums">{to12h(train.departs)}</span>
                        </div>
                        <div className="h-6 w-[1px] bg-outline-variant" />
                        <div>
                            <span className="block font-label-sm text-label-sm text-outline">Arrives</span>
                            <span className="font-label-md text-label-md font-bold text-on-surface tabular-nums">{to12h(train.arrives)}</span>
                        </div>
                    </div>
                ) : (
                    <span className="font-label-sm text-label-sm text-outline">Open for live status</span>
                )}
                <div className="flex items-center gap-3">
                    {!cancelled && (
                        <Link
                            href={urls.trainMap(train.number)}
                            onClick={onOpen}
                            className="relative z-10 inline-flex items-center gap-1 font-label-md text-label-md font-bold text-primary hover:underline"
                        >
                            <Icon name="map" className="text-base" />
                            <span>Live Map</span>
                        </Link>
                    )}
                    <span className="inline-flex items-center gap-1 font-label-md text-label-md font-bold text-primary transition-transform group-hover:translate-x-0.5">
                        <span>Details</span>
                        <Icon name="chevron_right" className="text-base" />
                    </span>
                </div>
            </div>
        </article>
    );
}

export function TrainResultSkeleton() {
    return (
        <div className="animate-pulse space-y-3 rounded-2xl border border-outline-variant/70 bg-surface-container-lowest p-4 shadow-sm">
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-xl bg-surface-container-high" />
                    <div className="space-y-1.5">
                        <div className="flex items-center gap-2">
                            <div className="h-4 w-14 rounded bg-surface-container-high" />
                            <div className="h-3 w-16 rounded bg-surface-container" />
                        </div>
                        <div className="h-4 w-36 rounded bg-surface-container-high" />
                        <div className="h-3 w-48 rounded bg-surface-container" />
                    </div>
                </div>
                <div className="h-6 w-16 rounded-full bg-surface-container-high" />
            </div>
            <div className="flex items-center justify-between border-t border-surface-container pt-3">
                <div className="flex gap-4">
                    <div className="h-7 w-16 rounded bg-surface-container" />
                    <div className="h-7 w-16 rounded bg-surface-container" />
                </div>
                <div className="h-5 w-20 rounded-md bg-surface-container-high" />
            </div>
        </div>
    );
}
