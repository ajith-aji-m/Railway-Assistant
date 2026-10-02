import { Icon } from '@/components/ui/Icon';

export function BoardEmpty({ type, stationName, onRefresh }: { type: 'arrivals' | 'departures'; stationName: string; onRefresh: () => void }) {
    const label = type === 'arrivals' ? 'Arrivals' : 'Departures';
    return (
        <div className="space-y-4 rounded-2xl border border-outline-variant/30 bg-surface-container-lowest p-space-xl text-center shadow-sm">
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-low text-primary">
                <Icon name="train" className="text-3xl" />
            </div>
            <div className="mx-auto max-w-xs space-y-1.5">
                <h2 className="font-headline-md text-headline-md font-bold text-on-surface">No {label} Available</h2>
                <p className="font-body-md text-body-md text-on-surface-variant">
                    There are no scheduled {label.toLowerCase()} {type === 'arrivals' ? 'at' : 'from'} {stationName} today.
                </p>
            </div>
            <div className="pt-2">
                <button
                    type="button"
                    onClick={onRefresh}
                    className="w-full rounded-xl bg-surface-container-low px-4 py-2.5 font-label-lg text-label-lg font-semibold text-primary transition-all hover:bg-surface-container-high active:scale-95"
                >
                    Refresh Timetable
                </button>
            </div>
        </div>
    );
}

export function BoardError({ onRetry }: { onRetry: () => void }) {
    return (
        <div className="space-y-4 rounded-2xl border border-tertiary-fixed/80 bg-surface-container-lowest p-space-xl text-center shadow-sm">
            <div className="mx-auto inline-flex items-center gap-1.5 rounded-full bg-error-container px-3 py-1 font-label-sm text-xs font-bold text-on-error-container">
                <Icon name="warning" className="text-sm" /> Service Alert
            </div>
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-error-container/60 text-tertiary">
                <Icon name="wifi_off" className="text-3xl" />
            </div>
            <div className="mx-auto max-w-xs space-y-1.5">
                <h2 className="font-headline-md text-headline-md font-bold text-on-surface">Unable to Load Live Timetable</h2>
                <p className="font-body-sm text-body-sm text-on-surface-variant">
                    We are experiencing trouble connecting to railway live feeds. Station Wi-Fi or data connection may be limited.
                </p>
            </div>
            <div className="flex flex-col gap-2.5 pt-2">
                <button
                    type="button"
                    onClick={onRetry}
                    className="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 font-label-lg text-label-lg font-bold text-on-primary shadow-sm transition-all hover:bg-primary/90 active:scale-[0.98]"
                >
                    <Icon name="refresh" className="text-lg" /> Retry Connection
                </button>
            </div>
        </div>
    );
}
