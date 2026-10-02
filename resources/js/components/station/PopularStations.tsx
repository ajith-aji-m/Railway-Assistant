import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/Icon';
import { urls } from '@/lib/urls';
import type { StationDetail } from '@/types/railway';

/** "Popular Hubs Across Network" card (no-stations state). */
export function PopularStations({ stations, title = 'Popular Hubs Across Network' }: { stations: StationDetail[]; title?: string }) {
    return (
        <div className="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 shadow-sm">
            <div className="mb-3 font-label-sm text-label-sm tracking-wider text-outline uppercase">{title}</div>
            <div className="space-y-2">
                {stations.map((s) => (
                    <Link
                        key={s.code}
                        href={urls.station(s.code)}
                        className="flex cursor-pointer items-center justify-between rounded-xl border border-outline-variant/40 p-2.5 transition-colors hover:bg-surface-container"
                    >
                        <div className="flex items-center gap-2.5">
                            <span className="flex h-8 min-w-8 items-center justify-center rounded-lg bg-surface-container px-1 font-label-sm text-label-sm font-bold text-primary">
                                {s.code}
                            </span>
                            <div>
                                <p className="font-label-lg text-label-lg font-bold text-on-surface">{s.name}</p>
                                <p className="font-body-sm text-body-sm text-on-surface-variant">
                                    {[s.platforms !== null ? `${s.platforms} Platforms` : null, s.city].filter(Boolean).join(' • ')}
                                </p>
                            </div>
                        </div>
                        <Icon name="chevron_right" className="text-outline" />
                    </Link>
                ))}
            </div>
        </div>
    );
}
