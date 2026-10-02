import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { EstimatedCard, GpsLostBanner, MapControls, MapErrorOverlay, MapHeaderCard, MapLoadingOverlay, TelemetryCard } from '@/components/map/MapOverlays';
import { RailMap, type RailMapHandle } from '@/components/map/RailMap';
import { rememberTrain } from '@/hooks/useLastTrain';
import { useLiveReload } from '@/hooks/useLiveReload';
import { useTrackedPosition } from '@/hooks/useTrackedPosition';
import { withLastKnownPosition } from '@/lib/live';
import { goBack } from '@/lib/navigation';
import { sharePage } from '@/lib/share';
import { urls } from '@/lib/urls';
import type { SharedProps } from '@/types/inertia';
import type { LiveStatus, TrainDetail } from '@/types/railway';

const LIVE_PROPS = ['live'];

export default function TrainLiveMap({ train, live: latestLive }: { train: TrainDetail; live: LiveStatus }) {
    // If a refresh has no coordinates, keep the last real position (marked stale/estimated).
    const lastLive = useRef<LiveStatus | null>(null);
    const live = withLastKnownPosition(lastLive.current, latestLive);
    lastLive.current = live;
    // Real fixes from RailRadar; a short local glide only between two real fixes.
    const tracked = useTrackedPosition(live, train.route);

    const mapRef = useRef<RailMapHandle>(null);
    const [tilesLoaded, setTilesLoaded] = useState(false);
    const [tileError, setTileError] = useState(false);
    const [labelsOn, setLabelsOn] = useState(true);
    const [mapKey, setMapKey] = useState(0);
    const [hudOffset, setHudOffset] = useState(240);
    const hudRef = useRef<HTMLElement>(null);
    // Server-configured: 15s for mock data, 5 min by default for RailRadar (monthly quota).
    // A failed background refresh keeps the current map; only manual refreshes show the overlay.
    const refreshSeconds = usePage<SharedProps>().props.liveRefresh.map;
    const refresh = useLiveReload(LIVE_PROPS, refreshSeconds > 0 ? refreshSeconds * 1000 : undefined, { keepDataOnPollError: true });

    useEffect(() => rememberTrain(train.number), [train.number]);

    // Keep the OSM attribution visible just above the floating bottom card.
    useEffect(() => {
        const el = hudRef.current;
        if (!el) return;
        const observer = new ResizeObserver(() => setHudOffset(el.offsetHeight + 80));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const gpsLost = live.gps === 'lost' && live.status === 'running';
    const share = () => sharePage(`${train.number} – ${train.name}`, `Live location of ${train.number} ${train.name}`);
    const retry = () => {
        setTileError(false);
        setTilesLoaded(false);
        setMapKey((k) => k + 1);
        refresh.reload();
    };

    return (
        <AppShell title={`Live Map · ${train.number}`} header={null} nav="map" padForNav={false} className="h-dvh min-h-0 overflow-hidden">
            <div className="rail-map absolute inset-0 z-0" style={{ ['--hud-offset' as string]: `${hudOffset}px` }}>
                <RailMap
                    key={mapKey}
                    ref={mapRef}
                    train={train}
                    live={live}
                    trainPosition={tracked.position}
                    showLabels={labelsOn}
                    padding={{ top: gpsLost ? 190 : 120, bottom: gpsLost ? 380 : 260 }}
                    onTilesLoaded={() => setTilesLoaded(true)}
                    onTileError={() => !tilesLoaded && setTileError(true)}
                />
            </div>
            {!tilesLoaded && !tileError && <MapLoadingOverlay />}
            {(tileError || refresh.error) && <MapErrorOverlay onRetry={retry} timetableHref={urls.train(train.number)} />}

            <header className="pointer-events-none relative z-20 w-full space-y-2 bg-gradient-to-b from-surface-container-lowest/80 to-transparent px-margin pt-3 pb-space-sm">
                <div className="pointer-events-auto">
                    <MapHeaderCard train={train} live={live} onBack={() => goBack(urls.train(train.number))} onShare={share} />
                </div>
                {gpsLost && (
                    <div className="pointer-events-auto">
                        <GpsLostBanner lastFixAt={live.snapshot?.lastKnown?.at ?? null} />
                    </div>
                )}
            </header>

            <div className={`absolute right-margin z-20 ${gpsLost ? 'top-44' : 'top-28'}`}>
                <MapControls
                    gpsLost={gpsLost}
                    labelsOn={labelsOn}
                    onRecenter={() => mapRef.current?.recenter()}
                    onZoomIn={() => mapRef.current?.zoomIn()}
                    onZoomOut={() => mapRef.current?.zoomOut()}
                    onToggleLabels={() => setLabelsOn((v) => !v)}
                />
            </div>

            <section ref={hudRef} className="absolute inset-x-0 bottom-[76px] z-20 px-margin">
                {gpsLost ? (
                    <EstimatedCard live={live} onShare={share} />
                ) : (
                    <TelemetryCard train={train} live={live} refreshing={refresh.loading} onRefresh={refresh.reload} stale={refresh.stale} />
                )}
            </section>
        </AppShell>
    );
}
