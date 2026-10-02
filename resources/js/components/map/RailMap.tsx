import type { LatLngBoundsExpression, LatLngExpression, Map as LeafletMap } from 'leaflet';
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef } from 'react';
import { MapContainer, Marker, Polyline, TileLayer, useMap } from 'react-leaflet';
import { nearestSegmentIndex } from '@/lib/geo';
import { stopBySequence } from '@/lib/live';
import { mapConfig } from '@/lib/mapConfig';
import type { LiveStatus, TrainDetail } from '@/types/railway';
import { stopLabelIcon, stopNodeIcon, trainIcon, type StopRole } from './markers';

export interface RailMapHandle {
    recenter: () => void;
    zoomIn: () => void;
    zoomOut: () => void;
}

interface RailMapProps {
    train: TrainDetail;
    live: LiveStatus;
    showLabels: boolean;
    /** Extra padding so the route is not hidden behind the floating header/HUD. */
    padding: { top: number; bottom: number };
    onTilesLoaded: () => void;
    onTileError: () => void;
}

const fitOptions = (padding: RailMapProps['padding']) => ({ paddingTopLeft: [40, padding.top] as [number, number], paddingBottomRight: [110, padding.bottom] as [number, number] });

/**
 * Initial view: the train's current section (previous → next-but-one stop), like the
 * close-up in the Stitch map; the whole route when the train is not running.
 */
function focusBounds(live: LiveStatus, route: LatLngExpression[]): LatLngBoundsExpression {
    if (live.status !== 'running' || live.lastStopSequence === null) return route as LatLngBoundsExpression;
    const index = live.stops.findIndex((s) => s.sequence === live.lastStopSequence);
    const slice = live.stops.slice(Math.max(0, index - 1), index + 2);
    const pts: [number, number][] = slice.map((s) => [s.lat, s.lng]);
    if (live.position) pts.push([live.position.lat, live.position.lng]);
    return pts;
}

function FitRoute({ bounds, padding }: { bounds: LatLngBoundsExpression; padding: RailMapProps['padding'] }) {
    const map = useMap();
    useEffect(() => {
        map.fitBounds(bounds, fitOptions(padding));
        // Fit once per train, not on every live refresh.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map]);
    return null;
}

export const RailMap = forwardRef<RailMapHandle, RailMapProps>(function RailMap({ train, live, showLabels, padding, onTilesLoaded, onTileError }, ref) {
    const mapRef = useRef<LeafletMap | null>(null);
    const route = train.route as LatLngExpression[];
    const estimated = live.gps === 'lost';

    const last = stopBySequence(live, live.lastStopSequence);
    const next = stopBySequence(live, live.nextStopSequence);

    // Completed track: the route geometry up to the segment the train is on, then its position.
    const completed = useMemo(() => {
        if (live.position && live.status === 'running' && train.route.length > 1) {
            const segment = nearestSegmentIndex(train.route, live.position);
            return [...train.route.slice(0, segment + 1), [live.position.lat, live.position.lng]] as LatLngExpression[];
        }
        // No position: departed stops only.
        return live.stops.filter((s) => s.state === 'departed' || s.state === 'current').map((s) => [s.lat, s.lng]) as LatLngExpression[];
    }, [live, train.route]);

    useImperativeHandle(ref, () => ({
        recenter: () => {
            const map = mapRef.current;
            if (!map) return;
            if (live.position) map.flyTo([live.position.lat, live.position.lng], Math.max(map.getZoom(), mapConfig.trainZoom), { duration: 0.8 });
            else map.fitBounds(route as LatLngBoundsExpression, fitOptions(padding));
        },
        zoomIn: () => mapRef.current?.zoomIn(),
        zoomOut: () => mapRef.current?.zoomOut(),
    }));

    const roleOf = (seq: number, index: number): StopRole => {
        if (live.status === 'running' && seq === next?.sequence) return 'next';
        if (live.status === 'running' && seq === last?.sequence && !live.atStation) return 'passed';
        if (index === 0) return 'origin';
        return 'other';
    };

    const tooltip = live.status === 'running' && next && !live.atStation ? `Next: ${next.station.name} (${Math.round(live.distanceToNextKm ?? 0)} km)` : null;

    return (
        <MapContainer
            ref={mapRef}
            bounds={route as LatLngBoundsExpression}
            zoomControl={false}
            attributionControl
            className="h-full w-full"
            maxZoom={mapConfig.maxZoom}
            zoomSnap={0.5}
        >
            <TileLayer
                url={mapConfig.tileUrl}
                attribution={mapConfig.attribution}
                eventHandlers={{ load: onTilesLoaded, tileerror: onTileError }}
            />
            <FitRoute bounds={focusBounds(live, route)} padding={padding} />

            {/* Track casing + main line (Stitch: white 7px under a blue 4.5px line) */}
            <Polyline positions={route} pathOptions={{ color: '#ffffff', weight: 7, opacity: 1, lineCap: 'round', lineJoin: 'round' }} />
            <Polyline positions={route} pathOptions={{ color: '#0d6efd', weight: 4.5, opacity: 0.9, lineCap: 'round', lineJoin: 'round' }} />
            {completed.length > 1 && (
                <Polyline
                    positions={completed}
                    pathOptions={{ color: estimated ? '#f59e0b' : '#6cf8bb', weight: 2, dashArray: '3,4', lineCap: 'round' }}
                />
            )}

            {live.stops.map((stop, i) => {
                const role = roleOf(stop.sequence, i);
                return (
                    <Marker key={`node-${stop.sequence}`} position={[stop.lat, stop.lng]} icon={stopNodeIcon(stop, role)} interactive={false} />
                );
            })}

            {showLabels &&
                live.stops.map((stop, i) => {
                    const role = roleOf(stop.sequence, i);
                    return (
                        <Marker
                            key={`label-${stop.sequence}-${role}`}
                            position={[stop.lat, stop.lng]}
                            icon={stopLabelIcon(stop, role, { nextKm: live.distanceToNextKm })}
                            interactive={false}
                            zIndexOffset={role === 'next' ? 500 : 0}
                        />
                    );
                })}

            {live.position && live.status !== 'cancelled' && (
                <Marker
                    position={[live.position.lat, live.position.lng]}
                    icon={trainIcon({ tooltip, estimated })}
                    interactive={false}
                    zIndexOffset={1000}
                />
            )}
        </MapContainer>
    );
});
