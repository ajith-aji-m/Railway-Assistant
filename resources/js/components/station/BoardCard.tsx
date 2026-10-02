import { Link } from '@inertiajs/react';
import { BoardStatusPill, DelayPill, PlatformBadge } from '@/components/train/Badges';
import { Icon } from '@/components/ui/Icon';
import { boardTimeInfo } from '@/lib/board';
import { cn, to12h } from '@/lib/format';
import { urls } from '@/lib/urls';
import type { BoardEntry } from '@/types/railway';

export function BoardCard({ entry }: { entry: BoardEntry }) {
    const cancelled = entry.status === 'cancelled';
    const past = entry.status === 'departed' || entry.status === 'arrived';
    const time = boardTimeInfo(entry);

    return (
        <Link
            href={urls.train(entry.trainNumber)}
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
                        {entry.trainNumber}
                    </span>
                    <span
                        className={cn(
                            'ml-1 truncate font-body-md text-body-md font-semibold',
                            cancelled ? 'text-outline line-through' : 'text-on-surface',
                        )}
                    >
                        {entry.trainName}
                    </span>
                </div>
                <div className="flex shrink-0 items-center space-x-1">
                    {time.showDelay && <DelayPill minutes={entry.delayMinutes!} />}
                    <Icon name="chevron_right" className="text-[18px] text-outline" />
                </div>
            </div>
            <div className="mt-2.5 flex items-center justify-between border-t border-surface-container pt-2">
                <div className="flex items-baseline space-x-2">
                    <span className={cn('font-metric-display text-metric-display leading-none font-extrabold tabular-nums', cancelled ? 'text-outline' : 'text-on-surface')}>
                        {to12h(entry.scheduledTime)}
                    </span>
                    {time.expectedLabel && (
                        <span className={cn('font-body-sm text-body-sm font-medium tabular-nums', time.delayed ? 'text-tertiary' : 'text-on-surface-variant')}>
                            {time.expectedLabel}
                        </span>
                    )}
                </div>
                <div className="flex items-center space-x-2">
                    <PlatformBadge platform={cancelled ? null : entry.platform} muted={cancelled} />
                    <BoardStatusPill status={entry.status} />
                </div>
            </div>
        </Link>
    );
}

export function BoardCardSkeleton({ dim = false }: { dim?: boolean }) {
    return (
        <div className={cn('space-y-3 rounded-2xl border border-outline-variant/30 bg-surface-container-lowest p-4 shadow-sm', dim && 'opacity-60')}>
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <div className="skeleton-shimmer h-5 w-16 rounded-md" />
                    <div className="skeleton-shimmer h-4 w-24 rounded" />
                </div>
                <div className="skeleton-shimmer h-5 w-16 rounded-full" />
            </div>
            <div className="flex items-center justify-between border-t border-surface-container-low pt-2">
                <div className="skeleton-shimmer h-6 w-20 rounded" />
                <div className="skeleton-shimmer h-4 w-28 rounded" />
            </div>
        </div>
    );
}
