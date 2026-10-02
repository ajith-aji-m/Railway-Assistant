import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StateCard } from '@/components/ui/StateCard';
import { StationListSkeleton } from './StationListItem';

export function PermissionState({ onAllow, onSearch }: { onAllow: () => void; onSearch: () => void }) {
    return (
        <div className="space-y-space-md">
            <div className="relative overflow-hidden rounded-2xl border border-outline-variant/80 bg-surface-container-lowest p-space-xl shadow-sm">
                <div className="pointer-events-none absolute -top-12 -right-12 flex h-40 w-40 items-center justify-center rounded-full border border-primary/10">
                    <div className="flex h-28 w-28 items-center justify-center rounded-full border border-primary/20">
                        <div className="h-16 w-16 rounded-full bg-surface-container-high/40" />
                    </div>
                </div>
                <div className="relative z-10 flex flex-col items-center text-center">
                    <div className="relative mb-space-lg flex items-center justify-center">
                        <div className="flex h-20 w-20 items-center justify-center rounded-full border border-outline-variant/50 bg-surface-container-low shadow-inner">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Icon name="near_me" fill className="text-3xl" />
                            </div>
                        </div>
                        <div className="pointer-events-none absolute inset-0 animate-ping rounded-full border-2 border-primary/30 opacity-25" />
                    </div>
                    <span className="mb-space-sm inline-flex items-center gap-1.5 rounded-full bg-surface-container-high px-3 py-1 font-label-sm text-label-sm font-bold text-primary">
                        <Icon name="satellite_alt" className="text-sm" />
                        GPS Precision Engine
                    </span>
                    <h2 className="mb-space-xs font-headline-lg text-headline-lg font-bold tracking-tight text-on-surface">Location Permission Needed</h2>
                    <p className="mb-space-xl max-w-xs font-body-md text-body-md leading-relaxed text-on-surface-variant">
                        Railway Assistant uses your device location to identify the closest railway stations, platforms, and arrival schedules.
                    </p>
                    <div className="w-full space-y-space-sm">
                        <Button icon="my_location" iconFill onClick={onAllow}>
                            Allow Location Access
                        </Button>
                        <Button variant="soft" icon="search" onClick={onSearch}>
                            Search Station Manually
                        </Button>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <FeatureTile icon="speed" iconClass="bg-secondary-container/40 text-on-secondary-container" title="Live Speed & Delay">
                    Instant platform alerts and onboard telemetry.
                </FeatureTile>
                <FeatureTile icon="battery_charging_full" iconClass="bg-surface-container-high text-primary" title="Battery Optimized">
                    Low-frequency triangulation on transit networks.
                </FeatureTile>
            </div>
        </div>
    );
}

function FeatureTile({ icon, iconClass, title, children }: { icon: string; iconClass: string; title: string; children: string }) {
    return (
        <div className="flex flex-col gap-1 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-3.5 shadow-sm">
            <div className={`mb-1 flex h-8 w-8 items-center justify-center rounded-lg ${iconClass}`}>
                <Icon name={icon} className="text-lg" />
            </div>
            <span className="font-label-md text-label-md font-bold text-on-surface">{title}</span>
            <span className="font-body-sm text-body-sm text-on-surface-variant">{children}</span>
        </div>
    );
}

export function DetectingState({ radiusKm }: { radiusKm: number }) {
    return (
        <div className="space-y-space-md">
            <div className="flex items-center justify-between rounded-2xl border border-outline-variant/80 bg-surface-container-lowest p-space-lg shadow-sm">
                <div className="flex items-center gap-3">
                    <div className="relative flex h-11 w-11 items-center justify-center overflow-hidden rounded-full bg-primary/10">
                        <div className="radar-beam absolute inset-0" />
                        <Icon name="radar" className="z-10 animate-spin text-xl text-primary [animation-duration:4s]" />
                    </div>
                    <div>
                        <div className="flex items-center gap-1.5">
                            <span className="font-headline-md text-headline-md font-bold text-on-surface">Triangulating GPS</span>
                            <span className="h-2 w-2 animate-pulse rounded-full bg-primary" />
                        </div>
                        <p className="font-body-sm text-body-sm text-on-surface-variant">Scanning track coordinates within {radiusKm} km...</p>
                    </div>
                </div>
                <span className="rounded-md bg-surface-container px-2.5 py-1 font-label-sm text-label-sm font-bold text-on-surface-variant">GPS</span>
            </div>

            <div className="space-y-space-sm">
                <div className="flex items-center justify-between px-1">
                    <span className="font-label-sm text-label-sm tracking-wider text-outline uppercase">Nearby Stations</span>
                    <span className="font-label-sm text-label-sm font-bold text-primary">Scanning...</span>
                </div>
                <StationListSkeleton />
            </div>

            <div className="flex items-center gap-2.5 rounded-xl border border-outline-variant/50 bg-surface-container-low p-3">
                <Icon name="info" className="text-base text-primary" />
                <span className="font-body-sm text-body-sm text-on-surface-variant">Station platforms update automatically as soon as lock is acquired.</span>
            </div>
        </div>
    );
}

export function NoStationsState({ radiusKm, onSearch, onExtend }: { radiusKm: number; onSearch: () => void; onExtend?: () => void }) {
    return (
        <StateCard
            icon="search_off"
            badge={`Radius: ${radiusKm} km`}
            title="No Railway Stations Nearby"
            description={`No stations were detected within ${radiusKm} km of your location. You might be off-grid or away from electrified lines.`}
        >
            <Button icon="search" onClick={onSearch}>
                Search Manually
            </Button>
            {onExtend && (
                <Button variant="neutral" icon="tune" onClick={onExtend}>
                    Adjust Radius (Extend to 100 km)
                </Button>
            )}
        </StateCard>
    );
}

export function LocationErrorState({
    denied,
    timedOut = false,
    onRetry,
    onSearch,
}: {
    denied: boolean;
    timedOut?: boolean;
    onRetry: () => void;
    onSearch: () => void;
}) {
    return (
        <div className="space-y-space-md">
            <StateCard
                tone="error"
                icon="warning"
                badge={denied ? 'Location Access Denied' : timedOut ? 'Location Request Timed Out' : 'GPS Service Unavailable'}
                title="Unable to Detect Location"
                description="Check that location services are turned on in your device system settings, or proceed with manual code entry."
            >
                <Button icon="refresh" onClick={onRetry}>
                    Try Again
                </Button>
                <Button variant="neutral" icon="pin" onClick={onSearch}>
                    Enter Station Code
                </Button>
            </StateCard>
            <div className="space-y-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 shadow-sm">
                <span className="block font-label-sm text-label-sm tracking-wider text-outline uppercase">Troubleshooting Checklist</span>
                {['Ensure Wi-Fi or Cellular Data is enabled', 'Allow location when browser prompts'].map((item) => (
                    <div key={item} className="flex items-center gap-2 font-body-sm text-body-sm text-on-surface-variant">
                        <Icon name="check_circle" fill className="text-base text-secondary" />
                        <span>{item}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
