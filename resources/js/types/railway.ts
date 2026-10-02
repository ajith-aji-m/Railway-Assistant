// Mirrors the PHP DTOs in app/Railway/Data. Keep the two in sync.

export type BoardType = 'arrivals' | 'departures';
export type BoardPhase = 'completed' | 'running' | 'upcoming';
export type RunningStatus = 'scheduled' | 'running' | 'completed' | 'cancelled';
export type StopState = 'departed' | 'current' | 'next' | 'upcoming';
export type BoardStatus = 'scheduled' | 'expected' | 'approaching' | 'at_station' | 'arrived' | 'departed' | 'cancelled';
export type GpsStatus = 'active' | 'lost';
export type FacilityKey = 'wifi' | 'food' | 'taxi' | 'elevator' | 'charging';

export interface LatLng {
    lat: number;
    lng: number;
}

export interface StationRef {
    code: string;
    name: string;
}

// city / state / lat / lng: null = not provided by the data source
export interface StationSummary extends StationRef {
    city: string | null;
    state: string | null;
    lat: number | null;
    lng: number | null;
    distanceKm: number | null;
}

// null = not provided by the data source
export interface StationDetail extends StationRef {
    fullName: string | null;
    city: string | null;
    state: string | null;
    lat: number | null;
    lng: number | null;
    platforms: number | null;
    image: string | null;
    facilities: FacilityKey[] | null;
}

export interface BoardEntry {
    trainNumber: string;
    trainName: string;
    trainType: string;
    from: StationRef;
    to: StationRef;
    scheduledTime: string;
    expectedTime: string | null;
    platform: string | null;
    delayMinutes: number | null;
    status: BoardStatus;
    /** true when expectedTime/delay come from live data; false = timetable only. */
    isLive: boolean;
    /** Completed / running / upcoming at this station today. */
    phase: BoardPhase;
}

// status / delayMinutes / times: null = unknown (lookup result without live status).
export interface TrainSummary {
    number: string;
    name: string;
    type: string;
    from: StationRef;
    to: StationRef;
    departs: string | null;
    arrives: string | null;
    originPlatform: string | null;
    status: RunningStatus | null;
    delayMinutes: number | null;
    /** false = delay is only the timetable (not tracked yet): never "On time". */
    delayIsLive: boolean;
}

export interface StopStatus extends LatLng {
    sequence: number;
    station: StationRef;
    scheduledArrival: string | null;
    scheduledDeparture: string | null;
    expectedArrival: string | null;
    expectedDeparture: string | null;
    platform: string | null;
    distanceKm: number;
    delayMinutes: number | null;
    state: StopState;
}

export interface LiveStatus {
    status: RunningStatus;
    gps: GpsStatus;
    delayMinutes: number;
    speedKmh: number | null;
    position: LatLng | null;
    atStation: boolean;
    lastStopSequence: number | null;
    nextStopSequence: number | null;
    distanceFromLastKm: number | null;
    distanceToNextKm: number | null;
    journeyDate: string;
    updatedAt: string;
    stops: StopStatus[];
    /** Provider-reported location (may be a non-halting station); null for mock data. */
    currentLocation?: CurrentLocation | null;
    /** false = delays/expected times are timetable only (journey not started) — never "On time". */
    delayIsLive: boolean;
    /** Client-side only: position kept from an earlier update because the latest had none. */
    positionStaleSince?: string | null;
    /** Real provider position fixes (RailRadar); null for mock data. */
    snapshot?: PositionSnapshot | null;
}

export interface PositionFix extends LatLng {
    /** Provider's own lastUpdatedAt for this fix. */
    at: string;
}

export interface PositionSnapshot {
    /** `position` is a real fix (not estimated / missing). */
    authoritative: boolean;
    trackingMode: string | null;
    /** The real fix before the current one (interpolation start), if any. */
    previous: PositionFix | null;
    /** Most recent real fix — kept even when a later response has no coordinates. */
    lastKnown: PositionFix | null;
    /** Latest real fix is older than the stale threshold. */
    stale: boolean;
}

export interface CurrentLocation {
    station: StationRef;
    isHalt: boolean;
    distanceFromStationKm: number | null;
}

export interface TrainDetail {
    number: string;
    name: string;
    type: string;
    from: StationRef;
    to: StationRef;
    departs: string;
    arrives: string;
    // null = not provided by the data source
    hasPantry: boolean | null;
    zone: string | null;
    image: string | null;
    wifiStations: StationRef[] | null;
    route: [number, number][];
    live: LiveStatus;
}
