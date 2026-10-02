import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/Icon';
import { useSettings } from '@/hooks/useSettings';
import { formatDistance } from '@/lib/format';
import { urls } from '@/lib/urls';
import type { StationSummary } from '@/types/railway';

export function StationListItem({ station, onSelect }: { station: StationSummary; onSelect?: () => void }) {
    const [{ distanceUnit }] = useSettings();

    return (
        <Link
            href={urls.station(station.code)}
            onClick={onSelect}
            className="group flex cursor-pointer items-center justify-between rounded-2xl border border-outline-variant/35 bg-surface-container-lowest p-3.5 shadow-sm transition-all duration-150 hover:border-primary/40 hover:shadow-md active:scale-[0.99]"
        >
            <div className="flex items-center space-x-3.5">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-container-low text-primary transition-colors duration-150 group-hover:bg-primary-container group-hover:text-on-primary-container">
                    <Icon name="train" className="text-[22px]" />
                </div>
                <div className="flex flex-col">
                    <div className="flex items-center space-x-1.5">
                        <span className="font-body-lg text-body-lg font-bold text-on-surface transition-colors group-hover:text-primary">
                            {station.name}
                        </span>
                        <span className="rounded bg-surface-container-high px-1.5 py-0.5 font-label-sm text-label-sm font-bold text-on-surface-variant">
                            ({station.code})
                        </span>
                    </div>
                    <span className="mt-0.5 font-body-sm text-body-sm font-medium text-outline">
                        {station.distanceKm !== null
                            ? `${formatDistance(station.distanceKm, distanceUnit)} away`
                            : [station.city, station.state].filter(Boolean).join(', ') || '—'}
                    </span>
                </div>
            </div>
            <Icon name="chevron_right" className="text-[20px] text-outline-variant transition-colors group-hover:text-primary" />
        </Link>
    );
}

export function StationListSkeleton({ count = 3 }: { count?: number }) {
    const widths = [
        ['w-4/6', 'w-2/4'],
        ['w-3/5', 'w-2/5'],
        ['w-4/5', 'w-1/3'],
    ];
    return (
        <>
            {Array.from({ length: count }, (_, i) => (
                <div
                    key={i}
                    className="flex animate-pulse items-center justify-between rounded-2xl border border-outline-variant/70 bg-surface-container-lowest p-4 shadow-sm"
                    style={{ animationDelay: `${i * 150}ms` }}
                >
                    <div className="flex flex-1 items-center gap-3">
                        <div className="h-12 w-12 shrink-0 rounded-xl bg-surface-container-highest" />
                        <div className="w-3/4 space-y-2">
                            <div className={`h-4 rounded-md bg-surface-container-highest ${widths[i % 3][0]}`} />
                            <div className={`h-3 rounded-md bg-surface-container-high ${widths[i % 3][1]}`} />
                        </div>
                    </div>
                    <div className="h-8 w-8 shrink-0 rounded-lg bg-surface-container-high" />
                </div>
            ))}
        </>
    );
}
