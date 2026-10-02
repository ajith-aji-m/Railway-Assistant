import { Icon } from '@/components/ui/Icon';
import type { StationDetail } from '@/types/railway';
import { FACILITIES } from './facilities';

/**
 * Platform count + facility chips, taken from the alternate Stitch dashboard header
 * and placed under the photo header.
 */
export function StationInfoStrip({ station }: { station: StationDetail }) {
    return (
        <section className="space-y-2.5 bg-surface-container-lowest px-margin pt-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <span className="flex items-center gap-1 font-label-md text-label-md font-bold text-secondary">
                        <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-secondary" />
                        Live Feed Active
                    </span>
                    {station.fullName && station.fullName !== station.name && <p className="mt-0.5 font-body-sm text-body-sm text-on-surface-variant">{station.fullName}</p>}
                </div>
                <div className="shrink-0 text-right">
                    <span className="block font-metric-display text-metric-display leading-none text-primary tabular-nums">{station.platforms ?? '—'}</span>
                    <span className="font-label-sm text-label-sm tracking-wider text-on-surface-variant uppercase">Platforms</span>
                </div>
            </div>
            {station.facilities && station.facilities.length > 0 && (
                <div className="no-scrollbar flex items-center gap-2 overflow-x-auto py-1 text-on-surface-variant">
                    {station.facilities.map((key) => (
                        <span key={key} className="inline-flex shrink-0 items-center gap-1 rounded-full bg-surface-container-low px-2.5 py-1 text-xs font-medium">
                            <Icon name={FACILITIES[key].icon} className="text-sm text-primary" />
                            {FACILITIES[key].label}
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}
