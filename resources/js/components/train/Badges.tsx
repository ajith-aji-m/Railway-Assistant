import { Icon } from '@/components/ui/Icon';
import { cn, delayShort } from '@/lib/format';
import type { BoardStatus, RunningStatus } from '@/types/railway';

/** "+7m" (coral) or "On time" (emerald) pill. */
export function DelayPill({ minutes, className }: { minutes: number; className?: string }) {
    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 font-label-sm text-label-sm font-bold tabular-nums',
                minutes > 0 ? 'bg-tertiary-fixed text-on-tertiary-fixed-variant' : 'bg-secondary/10 text-secondary',
                className,
            )}
        >
            {delayShort(minutes)}
        </span>
    );
}

export function PlatformBadge({ platform, short = false, muted = false }: { platform: string | null; short?: boolean; muted?: boolean }) {
    return (
        <span
            className={cn(
                'rounded bg-surface-container-high px-2 py-0.5 font-label-sm text-label-sm font-bold tracking-wider uppercase',
                muted ? 'text-outline' : 'text-on-surface-variant',
            )}
        >
            {platform ? `${short ? 'PF' : 'Platform'} ${platform}` : '--'}
        </span>
    );
}

const boardStatus: Record<BoardStatus, { label: string; className: string }> = {
    expected: { label: 'Expected', className: 'bg-secondary/10 text-secondary' },
    approaching: { label: 'Approaching', className: 'bg-primary-fixed text-primary' },
    at_station: { label: 'At Station', className: 'bg-surface-container-highest text-on-surface border border-outline-variant/60' },
    arrived: { label: 'Arrived', className: 'bg-surface-container-high text-on-surface-variant' },
    departed: { label: 'Departed', className: 'bg-surface-container-high text-on-surface-variant' },
    cancelled: { label: 'Cancelled', className: 'bg-surface-container-high text-outline' },
};

export function BoardStatusPill({ status }: { status: BoardStatus }) {
    const { label, className } = boardStatus[status];
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-label-sm text-label-sm font-bold', className)}>
            {status === 'approaching' && <span className="h-1.5 w-1.5 animate-ping rounded-full bg-primary" />}
            {status === 'at_station' && <Icon name="train" className="text-xs text-primary" />}
            {status === 'cancelled' && <Icon name="block" className="text-xs" />}
            {label}
        </span>
    );
}

const running: Record<RunningStatus, { label: string; className: string; dot: string }> = {
    running: { label: 'Running', className: 'bg-secondary-container/60 text-on-secondary-container', dot: 'bg-secondary' },
    scheduled: { label: 'Scheduled', className: 'bg-primary-fixed text-primary', dot: 'bg-primary' },
    completed: { label: 'Arrived', className: 'bg-surface-container-high text-on-surface-variant', dot: 'bg-outline' },
    cancelled: { label: 'Cancelled', className: 'bg-error-container text-on-error-container', dot: 'bg-tertiary' },
};

/** "● Running" pill on train headers. */
export function RunningPill({ status, className }: { status: RunningStatus; className?: string }) {
    const s = running[status];
    return (
        <span className={cn('inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 font-label-md text-label-md font-bold', s.className, className)}>
            <span className={cn('h-1.5 w-1.5 rounded-full', s.dot)} />
            {s.label}
        </span>
    );
}

export const runningLabel = (status: RunningStatus) => running[status].label;
