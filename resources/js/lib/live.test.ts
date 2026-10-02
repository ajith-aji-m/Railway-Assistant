import { describe, expect, it } from 'vitest';
import type { LiveStatus, StopStatus, TrainDetail } from '@/types/railway';
import { delayDisplay, describeLocation, mapLocationDetail, withLastKnownPosition } from './live';

const train: Pick<TrainDetail, 'from' | 'to' | 'departs'> = {
    from: { code: 'MAS', name: 'MGR Chennai Central' },
    to: { code: 'CBE', name: 'Coimbatore Jn' },
    departs: '06:10',
};

function stop(sequence: number, code: string, name: string, state: StopStatus['state']): StopStatus {
    return {
        sequence,
        station: { code, name },
        lat: 0,
        lng: 0,
        scheduledArrival: null,
        scheduledDeparture: null,
        expectedArrival: '11:04',
        expectedDeparture: null,
        platform: null,
        distanceKm: 0,
        delayMinutes: null,
        state,
    };
}

/** Shape of normalized RailRadar data for 12675 (real values from the API test). */
function railRadarLive(overrides: Partial<LiveStatus> = {}): LiveStatus {
    return {
        status: 'running',
        gps: 'active',
        delayMinutes: 2,
        speedKmh: null,
        position: { lat: 11.687485, lng: 78.10273 },
        atStation: false,
        lastStopSequence: 63,
        nextStopSequence: 72,
        distanceFromLastKm: 62.9,
        distanceToNextKm: 3.2,
        journeyDate: '2026-10-02',
        updatedAt: '2026-10-02T11:00:16+05:30',
        stops: [stop(63, 'MAP', 'Morappur', 'departed'), stop(72, 'SA', 'Salem Jn', 'next')],
        currentLocation: { station: { code: 'MGSJ', name: 'Magnesite Jn' }, isHalt: false, distanceFromStationKm: 1.1 },
        delayIsLive: true,
        ...overrides,
    };
}

describe('live map location text', () => {
    it('uses the RailRadar current location for the title', () => {
        expect(describeLocation(train, railRadarLive()).title).toBe('Near Magnesite Jn (MGSJ)');
    });

    it('uses the RailRadar location for the map detail line, not "km past previous halt"', () => {
        const detail = mapLocationDetail(train, railRadarLive());

        expect(detail).toBe('1.1 km past Magnesite Jn • Heading to Salem Jn');
        expect(detail).not.toContain('Morappur');
        expect(detail).not.toMatch(/63 km past/);
    });

    it('does not invent a distance when RailRadar omits it', () => {
        const live = railRadarLive({ currentLocation: { station: { code: 'MGSJ', name: 'Magnesite Jn' }, isHalt: false, distanceFromStationKm: null } });

        expect(mapLocationDetail(train, live)).toBe('Live position • Heading to Salem Jn');
    });

    it('keeps the existing mock wording when no provider location exists', () => {
        const mock = railRadarLive({ currentLocation: null, distanceFromLastKm: 25.5, distanceToNextKm: 58.4 });

        expect(describeLocation(train, mock).title).toBe('Near Morappur (MAP)');
        expect(mapLocationDetail(train, mock)).toBe('26 km past Morappur • Heading to Salem Jn');
    });

    it('describes a train standing at a station', () => {
        const live = railRadarLive({ atStation: true, lastStopSequence: 72, nextStopSequence: null });

        expect(describeLocation(train, live).title).toBe('At Salem Jn (SA)');
    });
});

describe('delay display (same rule on every screen)', () => {
    it('shows live delay', () => {
        expect(delayDisplay(railRadarLive({ delayMinutes: 14 }))).toEqual({ kind: 'late', text: '+14 minutes late', short: '+14 min' });
    });

    it('shows on time only for live data', () => {
        expect(delayDisplay(railRadarLive({ delayMinutes: 0 })).kind).toBe('on_time');
    });

    it('never says "On time" for a timetable-only (not started) train', () => {
        const d = delayDisplay(railRadarLive({ status: 'scheduled', delayMinutes: 0, delayIsLive: false }));
        expect(d).toEqual({ kind: 'scheduled', text: 'Scheduled', short: 'Scheduled' });
    });

    it('handles cancelled trains', () => {
        expect(delayDisplay(railRadarLive({ status: 'cancelled' })).kind).toBe('cancelled');
    });
});

describe('withLastKnownPosition', () => {
    const first = railRadarLive();

    it('passes fresh positions through untouched', () => {
        const next = railRadarLive({ position: { lat: 11.6, lng: 78.0 }, updatedAt: '2026-10-02T11:05:00+05:30' });
        expect(withLastKnownPosition(first, next)).toBe(next);
    });

    it('keeps the last real position, marked stale, when an update has none', () => {
        const next = railRadarLive({ position: null, gps: 'lost', updatedAt: '2026-10-02T11:05:00+05:30' });
        const merged = withLastKnownPosition(first, next);

        expect(merged.position).toEqual(first.position);
        expect(merged.gps).toBe('lost'); // never claims an active fix
        expect(merged.positionStaleSince).toBe('2026-10-02T11:00:16+05:30');
        expect(merged.updatedAt).toBe('2026-10-02T11:05:00+05:30');
    });

    it('keeps the original stale time across several updates without coordinates', () => {
        const a = withLastKnownPosition(first, railRadarLive({ position: null, updatedAt: '2026-10-02T11:05:00+05:30' }));
        const b = withLastKnownPosition(a, railRadarLive({ position: null, updatedAt: '2026-10-02T11:10:00+05:30' }));
        expect(b.positionStaleSince).toBe('2026-10-02T11:00:16+05:30');
    });

    it('does not carry a position into another journey or a non-running train', () => {
        expect(withLastKnownPosition(first, railRadarLive({ position: null, journeyDate: '2026-10-03' })).position).toBeNull();
        expect(withLastKnownPosition(first, railRadarLive({ position: null, status: 'completed' })).position).toBeNull();
        expect(withLastKnownPosition(null, railRadarLive({ position: null })).position).toBeNull();
    });
});

describe('countdown reference time', () => {
    it('measures "in N min" from now, not from an older provider update', async () => {
        const { minutesUntil } = await import('./format');
        const updatedAt = '2026-10-02T11:40:00+05:30'; // RailRadar data from 11:40
        const now = '2026-10-02T11:52:00+05:30';
        expect(minutesUntil(now, '12:16')).toBe(24);
        expect(minutesUntil(updatedAt, '12:16')).toBe(36); // what the old code would have shown
    });
});
