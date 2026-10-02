// Mirrors the PHP DTOs in app/Railway/Data. Keep the two in sync.

export type BoardType = 'arrivals' | 'departures';
export type RunningStatus = 'scheduled' | 'running' | 'completed' | 'cancelled';
export type StopState = 'departed' | 'current' | 'next' | 'upcoming';
export type BoardStatus = 'expected' | 'approaching' | 'at_station' | 'arrived' | 'departed' | 'cancelled';
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
}

export interface TrainSummary {
    number: string;
    name: string;
    type: string;
    from: StationRef;
    to: StationRef;
    departs: string;
    arrives: string;
    originPlatform: string | null;
    status: RunningStatus;
    delayMinutes: number;
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
