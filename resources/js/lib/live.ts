import type { LiveStatus, StopStatus, TrainDetail } from '@/types/railway';

export const stopBySequence = (live: LiveStatus, sequence: number | null): StopStatus | undefined =>
    sequence === null ? undefined : live.stops.find((s) => s.sequence === sequence);

export const stationLabel = (stop: StopStatus) => `${stop.station.name} (${stop.station.code})`;

/** Headline + detail for the "Current Location" card / map bottom sheet. */
export function describeLocation(train: Pick<TrainDetail, 'from' | 'to' | 'departs'>, live: LiveStatus): { title: string; detail: string } {
    const last = stopBySequence(live, live.lastStopSequence);
    const next = stopBySequence(live, live.nextStopSequence);

    switch (live.status) {
        case 'cancelled':
            return { title: 'Cancelled', detail: 'This train is not running today' };
        case 'scheduled':
            return { title: `At ${train.from.name} (${train.from.code})`, detail: `Departs at ${train.departs}` };
        case 'completed':
            return { title: `Arrived ${train.to.name} (${train.to.code})`, detail: 'Journey completed' };
    }

    if (live.atStation && last) {
        return { title: `At ${stationLabel(last)}`, detail: last.platform ? `Platform ${last.platform}` : 'Halted at station' };
    }

    // Real location from the provider (e.g. RailRadar): the station just passed.
    const reported = live.currentLocation;
    if (reported) {
        const label = `${reported.station.name} (${reported.station.code})`;
        const km = reported.distanceFromStationKm;
        return { title: `Near ${label}`, detail: km !== null ? `${km} km from ${reported.station.name}` : 'Live position' };
    }

    if (last && next) {
        const fromLast = live.distanceFromLastKm ?? 0;
        const toNext = live.distanceToNextKm ?? 0;
        return fromLast <= toNext
            ? { title: `Near ${stationLabel(last)}`, detail: `${Math.round(fromLast)} km from ${last.station.name}` }
            : { title: `Near ${stationLabel(next)}`, detail: `${Math.round(toNext)} km to ${next.station.name}` };
    }

    return { title: '--', detail: '' };
}

/**
 * Detail line under "Current Location" on the live map. Uses the provider-reported
 * location when available (RailRadar), otherwise distances to the surrounding halts (mock).
 */
export function mapLocationDetail(train: Pick<TrainDetail, 'from' | 'to' | 'departs'>, live: LiveStatus): string {
    const last = stopBySequence(live, live.lastStopSequence);
    const next = stopBySequence(live, live.nextStopSequence);
    const heading = next ? ` • Heading to ${next.station.name}` : '';

    if (live.status !== 'running' || live.atStation) {
        return describeLocation(train, live).detail;
    }

    const reported = live.currentLocation;
    if (reported) {
        const km = reported.distanceFromStationKm;
        return km !== null ? `${km} km past ${reported.station.name}${heading}` : `Live position${heading}`;
    }

    if (last && next) {
        const fromLast = Math.round(live.distanceFromLastKm ?? 0);
        const toNext = Math.round(live.distanceToNextKm ?? 0);
        return fromLast <= toNext
            ? `${fromLast} km past ${last.station.name} • Heading to ${next.station.name}`
            : `${toNext} km to ${next.station.name} • Approaching station`;
    }

    return describeLocation(train, live).detail;
}

export interface DelayDisplay {
    kind: 'late' | 'on_time' | 'scheduled' | 'cancelled';
    /** Long form for the Train Details delay card. */
    text: string;
    /** Short form for the map header pill. */
    short: string;
}

/** One rule for every screen: only live data may say "late" or "On time". */
export function delayDisplay(live: Pick<LiveStatus, 'status' | 'delayMinutes' | 'delayIsLive'>): DelayDisplay {
    if (live.status === 'cancelled') return { kind: 'cancelled', text: 'Cancelled', short: 'Cancelled' };
    if (live.delayIsLive === false) return { kind: 'scheduled', text: 'Scheduled', short: 'Scheduled' };
    if (live.delayMinutes > 0) return { kind: 'late', text: `+${live.delayMinutes} minutes late`, short: `+${live.delayMinutes} min` };
    return { kind: 'on_time', text: 'On time', short: 'On time' };
}

/**
 * Keep the last real position when a newer update for the same journey has none,
 * marking it stale (shown with the existing GPS-lost / estimated styling).
 */
export function withLastKnownPosition(previous: LiveStatus | null, next: LiveStatus): LiveStatus {
    if (next.position || next.status !== 'running') return next;

    // Server-side snapshot remembers the last real fix (survives page reloads).
    const last = next.snapshot?.lastKnown;
    if (last) {
        return { ...next, position: { lat: last.lat, lng: last.lng }, gps: 'lost', positionStaleSince: last.at };
    }

    if (!previous?.position || previous.journeyDate !== next.journeyDate) {
        return next;
    }
    return {
        ...next,
        position: previous.position,
        gps: 'lost',
        positionStaleSince: previous.positionStaleSince ?? previous.updatedAt,
    };
}

const ZONES: Record<string, string> = {
    SR: 'Southern',
    SWR: 'South Western',
    SER: 'South Eastern',
    SCR: 'South Central',
    ECoR: 'East Coast',
};

export const zoneLabel = (zone: string) => `${ZONES[zone] ?? zone} (${zone})`;
