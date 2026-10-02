import type { ReactNode } from 'react';
import { cn } from '@/lib/format';
import { Icon } from './Icon';

interface StateCardProps {
    tone?: 'neutral' | 'error';
    icon: string;
    badge?: string;
    title: string;
    description: ReactNode;
    children?: ReactNode; // action buttons
}

/** Empty / error hero card (station selection "No Stations" and "Error" states). */
export function StateCard({ tone = 'neutral', icon, badge, title, description, children }: StateCardProps) {
    const error = tone === 'error';
    return (
        <div
            className={cn(
                'rounded-2xl border bg-surface-container-lowest p-space-xl text-center shadow-sm',
                error ? 'border-error/20' : 'border-outline-variant/80',
            )}
        >
            <div
                className={cn(
                    'mx-auto mb-space-md flex h-16 w-16 items-center justify-center rounded-2xl',
                    error ? 'bg-error-container text-on-error-container' : 'bg-surface-container-high text-on-surface-variant',
                )}
            >
                <Icon name={icon} fill={error} className="text-3xl" />
            </div>
            {badge && (
                <span
                    className={cn(
                        'mb-space-xs inline-block rounded-full px-3 py-1 font-label-sm text-label-sm font-bold',
                        error ? 'bg-error-container/60 text-on-error-container' : 'bg-surface-container text-on-surface-variant',
                    )}
                >
                    {badge}
                </span>
            )}
            <h3 className="mb-2 font-headline-lg text-headline-lg font-bold tracking-tight text-on-surface">{title}</h3>
            <p className="mx-auto mb-space-xl max-w-xs font-body-md text-body-md text-on-surface-variant">{description}</p>
            {children && <div className="flex w-full flex-col gap-2.5">{children}</div>}
        </div>
    );
}
